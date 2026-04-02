#!/usr/bin/env python3
from __future__ import annotations

import logging
import os
import time
from datetime import datetime
from logging.handlers import RotatingFileHandler
from typing import List, Dict, Optional
from zoneinfo import ZoneInfo

import mysql.connector
import requests

from db_config import DB_CONFIG


BOT_NAME = "AI_ADVICE"
APP_TIMEZONE = "Europe/Amsterdam"
LOG_FILE = "/home/hans/trading_project/ai_advice.log"
LOG_LEVEL = os.getenv("TRADING_LOG_LEVEL", "INFO").upper()

MISTRAL_API_KEY = os.getenv("MISTRAL_API_KEY", "2rP5DYZa8bDvmy8IjOg8VshUsDCZ63qZ").strip()
MISTRAL_API_URL = "https://api.mistral.ai/v1/chat/completions"
MISTRAL_MODEL = os.getenv("MISTRAL_MODEL", "mistral-tiny").strip() or "mistral-tiny"
REQUEST_TIMEOUT_SECONDS = 45
MAX_ASSETS = 9

os.environ["TZ"] = APP_TIMEZONE
try:
    time.tzset()
except AttributeError:
    pass

logger = logging.getLogger("generate_ai_advice")
logger.setLevel(getattr(logging, LOG_LEVEL, logging.INFO))
logger.handlers.clear()


class AmsterdamFormatter(logging.Formatter):
    def formatTime(self, record, datefmt=None):
        dt = datetime.fromtimestamp(record.created, tz=ZoneInfo(APP_TIMEZONE))
        if datefmt:
            return dt.strftime(datefmt)
        return dt.strftime("%Y-%m-%d %H:%M:%S")


formatter = AmsterdamFormatter("%(asctime)s | %(levelname)s | %(message)s")

file_handler = RotatingFileHandler(LOG_FILE, maxBytes=2_000_000, backupCount=5)
file_handler.setFormatter(formatter)
logger.addHandler(file_handler)

stream_handler = logging.StreamHandler()
stream_handler.setFormatter(formatter)
logger.addHandler(stream_handler)

_TABLE_COLUMNS_CACHE: dict[str, set[str]] = {}


def get_db_connection():
    return mysql.connector.connect(**DB_CONFIG.as_mysql_kwargs())


def get_table_columns(cur, table_name: str) -> set[str]:
    table_name = table_name.lower()
    if table_name in _TABLE_COLUMNS_CACHE:
        return _TABLE_COLUMNS_CACHE[table_name]

    cur.execute(f"SHOW COLUMNS FROM {table_name}")
    cols = {str(row[0]).lower() for row in cur.fetchall()}
    _TABLE_COLUMNS_CACHE[table_name] = cols
    return cols


def insert_with_existing_columns(cur, table_name: str, values: dict, required: Optional[set[str]] = None) -> int:
    cols = get_table_columns(cur, table_name)
    filtered = {k: v for k, v in values.items() if k.lower() in cols}

    required = required or set()
    missing_required = [col for col in required if col.lower() not in {k.lower() for k in filtered.keys()}]
    if missing_required:
        raise RuntimeError(f"Tabel {table_name} mist vereiste insertwaarden: {', '.join(missing_required)}")

    if not filtered:
        raise RuntimeError(f"Geen geldige kolommen om in {table_name} te inserten")

    insert_cols = list(filtered.keys())
    placeholders = ", ".join(["%s"] * len(insert_cols))
    sql = f"INSERT INTO {table_name} ({', '.join(insert_cols)}) VALUES ({placeholders})"
    cur.execute(sql, tuple(filtered[col] for col in insert_cols))
    return int(cur.lastrowid)


def write_bot_log_db(level: str, message: str) -> None:
    db = None
    cur = None
    try:
        db = get_db_connection()
        cur = db.cursor()
        insert_with_existing_columns(
            cur,
            "bot_logs",
            {
                "created_at": datetime.now(ZoneInfo(APP_TIMEZONE)).strftime("%Y-%m-%d %H:%M:%S"),
                "level": level.upper(),
                "message": message[:1000],
            },
            required={"level", "message"},
        )
        db.commit()
    except Exception as exc:
        logger.error("Kon niet naar bot_logs schrijven: %s", exc)
        if db is not None:
            try:
                db.rollback()
            except Exception:
                pass
    finally:
        if cur is not None:
            cur.close()
        if db is not None and db.is_connected():
            db.close()


def log_bot(stage: str, message: str = "", level: str = "INFO", db_log: bool = True) -> None:
    text = f"{BOT_NAME} {stage}".strip()
    if message:
        text += f" {message}"

    log_method = getattr(logger, level.lower(), logger.info)
    log_method(text)

    if db_log:
        write_bot_log_db(level, text)


def create_ai_advice_table() -> None:
    conn = None
    cur = None
    try:
        conn = get_db_connection()
        cur = conn.cursor()
        cur.execute(
            """
            CREATE TABLE IF NOT EXISTS ai_advice (
                id INT AUTO_INCREMENT PRIMARY KEY,
                symbol VARCHAR(40) NOT NULL,
                display_name VARCHAR(255),
                asset_type VARCHAR(50),
                exchange_name VARCHAR(100),
                currency VARCHAR(20),
                advice TEXT,
                evaluation TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            """
        )
        conn.commit()
    finally:
        if cur is not None:
            cur.close()
        if conn is not None and conn.is_connected():
            conn.close()


def get_active_assets() -> List[Dict]:
    conn = None
    cursor = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)

        query = f"""
            SELECT a.symbol, a.display_name, a.asset_type, a.exchange_name, a.currency
            FROM asset_universe a
            WHERE a.status = 'active'
              AND NOT EXISTS (
                  SELECT 1
                  FROM positions p
                  WHERE p.symbol = a.symbol
                    AND p.status = 'OPEN'
              )
            ORDER BY a.symbol
            LIMIT {int(MAX_ASSETS)}
        """

        cursor.execute(query)
        assets = cursor.fetchall()
        return assets

    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None and conn.is_connected():
            conn.close()


def get_recent_news(symbol: str) -> List[Dict]:
    conn = None
    cursor = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)

        query = """
            SELECT title, summary, published_at, sentiment_score, sentiment_label, importance_score
            FROM asset_news
            WHERE symbol = %s
            ORDER BY published_at DESC
            LIMIT 5
        """

        cursor.execute(query, (symbol,))
        news = cursor.fetchall()
        return news

    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None and conn.is_connected():
            conn.close()


def call_mistral(prompt: str) -> str:
    if not MISTRAL_API_KEY:
        raise RuntimeError("MISTRAL_API_KEY ontbreekt")

    headers = {
        "Content-Type": "application/json",
        "Authorization": f"Bearer {MISTRAL_API_KEY}",
    }

    data = {
        "model": MISTRAL_MODEL,
        "messages": [{"role": "user", "content": prompt}],
    }

    response = requests.post(
        MISTRAL_API_URL,
        headers=headers,
        json=data,
        timeout=REQUEST_TIMEOUT_SECONDS,
    )
    response.raise_for_status()

    result = response.json()
    return str(result["choices"][0]["message"]["content"]).strip()


def get_ai_advice(symbol: str, asset_info: Dict, news_items: List[Dict]) -> str:
    news_summary = (
        "\n- ".join(
            [f"{item.get('title', '')} ({item.get('sentiment_label') or 'UNKNOWN'})" for item in news_items]
        )
        if news_items
        else "No recent news found."
    )

    prompt = f"""
Geef een advies (koop, niet kopen, in de gaten houden) voor aandeel {symbol}.

Asset informatie:
- naam: {asset_info.get('display_name')}
- type: {asset_info.get('asset_type')}
- beurs: {asset_info.get('exchange_name')}
- valuta: {asset_info.get('currency')}

Relevant nieuws:
- {news_summary}

Geef een kort, duidelijk advies met motivatie in het Nederlands.
""".strip()

    return call_mistral(prompt)


def evaluate_ai_advice(symbol: str, advice: str) -> str:
    prompt = f"""
Beoordeel het volgende advies voor aandeel {symbol}:

\"\"\"{advice}\"\"\"

Geef:
1. een gewogen beslissing: koop / niet kopen / in de gaten houden
2. een betrouwbaarheidsscore van 0-100
3. een korte motivatie

Antwoord in het Nederlands.
""".strip()

    return call_mistral(prompt)


def save_advice_to_db(symbol: str, asset_info: Dict, advice: str, evaluation: str) -> None:
    conn = None
    cursor = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        query = """
            INSERT INTO ai_advice
            (symbol, display_name, asset_type, exchange_name, currency, advice, evaluation, created_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, NOW())
        """

        cursor.execute(
            query,
            (
                symbol,
                asset_info.get("display_name"),
                asset_info.get("asset_type"),
                asset_info.get("exchange_name"),
                asset_info.get("currency"),
                advice,
                evaluation,
            ),
        )

        conn.commit()

    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None and conn.is_connected():
            conn.close()


def process_asset(asset: Dict) -> dict:
    symbol = str(asset.get("symbol") or "").upper().strip()
    result = {
        "symbol": symbol,
        "status": "ERROR",
        "outcome": "ERROR",
        "message": "",
    }

    if not symbol:
        result["message"] = "LEEG_SYMBOOL"
        return result

    try:
        log_bot("INFO", f"symbol={symbol} status=START")

        news = get_recent_news(symbol)
        advice = get_ai_advice(symbol, asset, news)
        evaluation = evaluate_ai_advice(symbol, advice)
        save_advice_to_db(symbol, asset, advice, evaluation)

        result["status"] = "OK"
        result["outcome"] = "SAVED"
        result["message"] = f"news_items={len(news)}"

        log_bot(
            "INFO",
            f"symbol={symbol} status=OK outcome=SAVED news_items={len(news)}"
        )
        return result

    except requests.exceptions.RequestException as exc:
        result["status"] = "ERROR"
        result["outcome"] = "API_ERROR"
        result["message"] = str(exc)

        log_bot(
            "ERROR",
            f"symbol={symbol} status=FAILED outcome=API_ERROR error={exc}",
            level="ERROR",
        )
        return result

    except mysql.connector.Error as exc:
        result["status"] = "ERROR"
        result["outcome"] = "DB_ERROR"
        result["message"] = str(exc)

        log_bot(
            "ERROR",
            f"symbol={symbol} status=FAILED outcome=DB_ERROR error={exc}",
            level="ERROR",
        )
        return result

    except Exception as exc:
        result["status"] = "ERROR"
        result["outcome"] = "ERROR"
        result["message"] = str(exc)

        log_bot(
            "ERROR",
            f"symbol={symbol} status=FAILED outcome=ERROR error={exc}",
            level="ERROR",
        )
        return result


def main():
    run_started = time.time()

    total_assets = 0
    saved_count = 0
    error_count = 0

    log_bot("START", "run=begin")

    try:
        create_ai_advice_table()

        if not MISTRAL_API_KEY:
            duration = round(time.time() - run_started, 2)
            log_bot("ERROR", "status=FAILED reason=MISTRAL_API_KEY_ONTBREEKT", level="ERROR")
            log_bot("END", f"status=FAILED duration={duration}s", level="ERROR")
            return

        assets = get_active_assets()
        if not assets:
            duration = round(time.time() - run_started, 2)
            log_bot("END", f"status=STOPPED reason=GEEN_ACTIEVE_ASSETS duration={duration}s", level="WARNING")
            return

        total_assets = len(assets)
        log_bot("INFO", f"assets={total_assets} model={MISTRAL_MODEL}")

        for asset in assets:
            result = process_asset(asset)

            if result["status"] == "OK":
                saved_count += 1
            else:
                error_count += 1

        duration = round(time.time() - run_started, 2)

        if error_count > 0:
            log_bot(
                "END",
                f"status=PARTIAL duration={duration}s assets={total_assets} saved={saved_count} errors={error_count}",
                level="WARNING",
            )
        else:
            log_bot(
                "END",
                f"status=OK duration={duration}s assets={total_assets} saved={saved_count} errors=0"
            )

    except Exception as exc:
        duration = round(time.time() - run_started, 2)
        log_bot("ERROR", f"status=FAILED error={exc}", level="ERROR")
        log_bot("END", f"status=FAILED duration={duration}s", level="ERROR")


if __name__ == "__main__":
    main()