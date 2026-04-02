#!/usr/bin/env python3
from __future__ import annotations

import logging
import sys
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Set

import mysql.connector
import requests
from mysql.connector.connection import MySQLConnection

from db_config import DB_CONFIG

# ============================================================
# Config
# ============================================================
BOT_NAME = "ASSET_SYNC"
SCRIPT_NAME = "sync_crypto_assets.py"
COINGECKO_API_URL = "https://api.coingecko.com/api/v3/coins/list?include_platform=false"
REQUEST_TIMEOUT = 30

BASE_DIR = Path(__file__).resolve().parent
LOG_DIR = BASE_DIR / "logs"
LOG_DIR.mkdir(parents=True, exist_ok=True)

LOG_FILE = LOG_DIR / "sync_crypto_assets.log"
LOG_LEVEL = logging.INFO


# ============================================================
# Logging setup
# ============================================================
logger = logging.getLogger(BOT_NAME)
logger.setLevel(LOG_LEVEL)
logger.handlers.clear()
logger.propagate = False

formatter = logging.Formatter(
    "%(asctime)s | %(levelname)s | %(name)s | %(message)s"
)

console_handler = logging.StreamHandler(sys.stdout)
console_handler.setLevel(LOG_LEVEL)
console_handler.setFormatter(formatter)
logger.addHandler(console_handler)

file_handler = logging.FileHandler(LOG_FILE, encoding="utf-8")
file_handler.setLevel(LOG_LEVEL)
file_handler.setFormatter(formatter)
logger.addHandler(file_handler)


# ============================================================
# Helpers
# ============================================================
def safe_str(value: Any) -> str:
    if value is None:
        return ""
    return str(value).strip()


def normalize_symbol(symbol: Any) -> str:
    return safe_str(symbol).upper()


def get_db_connection() -> MySQLConnection:
    return mysql.connector.connect(**DB_CONFIG.as_mysql_kwargs())


def log_bot(level: str, message: str) -> None:
    """
    Log naar Python logger en naar bot_logs.
    Verwachte tabelstructuur:
      bot_logs(level, message, created_at)
    """
    level_upper = safe_str(level).upper() or "INFO"
    python_level = getattr(logging, level_upper, logging.INFO)
    full_message = f"{BOT_NAME} {message}"

    logger.log(python_level, full_message)

    conn: Optional[MySQLConnection] = None
    cur = None

    try:
        conn = get_db_connection()
        cur = conn.cursor()
        cur.execute(
            "INSERT INTO bot_logs (level, message) VALUES (%s, %s)",
            (level_upper, full_message),
        )
        conn.commit()
    except mysql.connector.Error as err:
        logger.error("%s DB LOG ERROR error=%s", BOT_NAME, err)
    except Exception as err:
        logger.error("%s DB LOG ERROR error=%s", BOT_NAME, err)
    finally:
        if cur is not None:
            cur.close()
        if conn is not None and conn.is_connected():
            conn.close()


def log_run_start() -> None:
    log_bot("INFO", f"START script={SCRIPT_NAME}")


def log_run_end(
    status: str,
    fetched: int,
    existing: int,
    candidates: int,
    inserted: int,
    skipped: int,
    duration_seconds: float,
) -> None:
    level = "INFO" if status == "SUCCESS" else "ERROR"
    log_bot(
        level,
        (
            f"END status={status} "
            f"fetched={fetched} existing={existing} "
            f"candidates={candidates} inserted={inserted} "
            f"skipped={skipped} duration={duration_seconds:.2f}s"
        ),
    )


# ============================================================
# Database functions
# ============================================================
def get_existing_crypto_symbols() -> Set[str]:
    conn: Optional[MySQLConnection] = None
    cur = None

    try:
        conn = get_db_connection()
        cur = conn.cursor()
        cur.execute(
            """
            SELECT symbol
            FROM asset_universe
            WHERE asset_type = 'crypto'
            """
        )
        symbols = {
            normalize_symbol(row[0])
            for row in cur.fetchall()
            if row and row[0]
        }

        log_bot("INFO", f"LOAD_EXISTING_CRYPTO count={len(symbols)}")
        return symbols

    except mysql.connector.Error as err:
        log_bot("ERROR", f"LOAD_EXISTING_CRYPTO_FAILED error={err}")
        return set()

    finally:
        if cur is not None:
            cur.close()
        if conn is not None and conn.is_connected():
            conn.close()


def add_new_crypto_assets(new_assets: List[Dict[str, Any]]) -> int:
    if not new_assets:
        return 0

    conn: Optional[MySQLConnection] = None
    cur = None
    inserted = 0

    try:
        conn = get_db_connection()
        cur = conn.cursor()

        sql = """
            INSERT IGNORE INTO asset_universe
            (
                symbol,
                display_name,
                asset_type,
                exchange_name,
                currency,
                provider,
                status,
                search_text,
                created_at,
                updated_at
            )
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
        """

        for asset in new_assets:
            raw_symbol = safe_str(asset.get("symbol"))
            raw_name = safe_str(asset.get("name"))
            raw_id = safe_str(asset.get("id"))

            symbol = normalize_symbol(raw_symbol)
            display_name = raw_name if raw_name else symbol

            if not symbol:
                continue

            search_text = f"{symbol} {display_name} {raw_id} crypto coingecko".strip()

            cur.execute(
                sql,
                (
                    symbol,
                    display_name,
                    "crypto",
                    "CoinGecko",
                    "USD",
                    "coingecko",
                    "active",
                    search_text,
                ),
            )

            if cur.rowcount > 0:
                inserted += 1

        conn.commit()
        log_bot("INFO", f"INSERT_NEW_CRYPTO inserted={inserted}")
        return inserted

    except mysql.connector.Error as err:
        if conn is not None and conn.is_connected():
            conn.rollback()
        log_bot("ERROR", f"INSERT_NEW_CRYPTO_FAILED error={err}")
        return inserted

    finally:
        if cur is not None:
            cur.close()
        if conn is not None and conn.is_connected():
            conn.close()


# ============================================================
# API functions
# ============================================================
def get_new_crypto_assets() -> List[Dict[str, Any]]:
    try:
        response = requests.get(
            COINGECKO_API_URL,
            timeout=REQUEST_TIMEOUT,
            headers={
                "Accept": "application/json",
                "User-Agent": f"{BOT_NAME}/{SCRIPT_NAME}",
            },
        )
        response.raise_for_status()

        data = response.json()
        if not isinstance(data, list):
            log_bot("ERROR", "FETCH_COINGECKO_INVALID_RESPONSE response_is_not_list")
            return []

        log_bot("INFO", f"FETCH_COINGECKO_OK count={len(data)}")
        return data

    except requests.RequestException as err:
        log_bot("ERROR", f"FETCH_COINGECKO_FAILED error={err}")
        return []
    except ValueError as err:
        log_bot("ERROR", f"FETCH_COINGECKO_JSON_FAILED error={err}")
        return []


# ============================================================
# Main
# ============================================================
def main() -> int:
    start_ts = time.time()

    fetched_count = 0
    existing_count = 0
    candidate_count = 0
    inserted_count = 0
    skipped_count = 0

    log_run_start()

    try:
        existing_symbols = get_existing_crypto_symbols()
        existing_count = len(existing_symbols)

        assets = get_new_crypto_assets()
        fetched_count = len(assets)

        if not assets:
            duration = time.time() - start_ts
            log_bot("WARNING", "NO_ASSETS_FETCHED")
            log_run_end(
                status="FAILED",
                fetched=fetched_count,
                existing=existing_count,
                candidates=0,
                inserted=0,
                skipped=0,
                duration_seconds=duration,
            )
            return 1

        new_assets: List[Dict[str, Any]] = []
        seen_symbols: Set[str] = set()

        for asset in assets:
            symbol = normalize_symbol(asset.get("symbol"))

            if symbol == "":
                skipped_count += 1
                continue

            if symbol in seen_symbols:
                skipped_count += 1
                continue

            seen_symbols.add(symbol)

            if symbol in existing_symbols:
                skipped_count += 1
                continue

            new_assets.append(asset)

        candidate_count = len(new_assets)

        log_bot(
            "INFO",
            (
                f"COMPARE_DONE fetched={fetched_count} "
                f"existing={existing_count} candidates={candidate_count} "
                f"skipped={skipped_count}"
            ),
        )

        if candidate_count > 0:
            inserted_count = add_new_crypto_assets(new_assets)
        else:
            log_bot("INFO", "NO_NEW_CRYPTO_ASSETS_FOUND")

        duration = time.time() - start_ts
        log_run_end(
            status="SUCCESS",
            fetched=fetched_count,
            existing=existing_count,
            candidates=candidate_count,
            inserted=inserted_count,
            skipped=skipped_count,
            duration_seconds=duration,
        )
        return 0

    except Exception as err:
        duration = time.time() - start_ts
        log_bot("ERROR", f"UNHANDLED_EXCEPTION error={err}")
        log_run_end(
            status="FAILED",
            fetched=fetched_count,
            existing=existing_count,
            candidates=candidate_count,
            inserted=inserted_count,
            skipped=skipped_count,
            duration_seconds=duration,
        )
        return 1


if __name__ == "__main__":
    raise SystemExit(main())