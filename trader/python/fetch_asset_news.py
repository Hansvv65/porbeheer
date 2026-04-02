#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import html
import logging
import os
import re
import time
from datetime import datetime
from logging.handlers import RotatingFileHandler
from pathlib import Path
from typing import Optional
from zoneinfo import ZoneInfo
from email.utils import parsedate_to_datetime

import feedparser
import mysql.connector

from db_config import DB_CONFIG


BOT_NAME = "NEWS_SYNC"
APP_TIMEZONE = "Europe/Amsterdam"
LOG_FILE = "/home/hans/trading_project/news_sync.log"
LOG_LEVEL = logging.INFO
MAX_ITEMS_PER_ASSET = 8
REQUEST_SLEEP_SECONDS = 1.0

os.environ["TZ"] = APP_TIMEZONE
try:
    time.tzset()
except AttributeError:
    pass

logger = logging.getLogger("fetch_asset_news")
logger.setLevel(LOG_LEVEL)
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


def get_db():
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


def upsert_asset_news(cur, item: dict) -> int:
    cols = get_table_columns(cur, "asset_news")
    values = {k: v for k, v in item.items() if k.lower() in cols}

    if not values:
        raise RuntimeError("Geen geldige kolommen voor asset_news upsert")

    insert_cols = list(values.keys())
    placeholders = ", ".join(["%s"] * len(insert_cols))
    update_cols = [col for col in insert_cols if col != "external_news_id"]

    update_sql = ", ".join([f"{col} = VALUES({col})" for col in update_cols])

    sql = f"""
        INSERT INTO asset_news ({", ".join(insert_cols)})
        VALUES ({placeholders})
        ON DUPLICATE KEY UPDATE {update_sql}
    """
    cur.execute(sql, tuple(values[col] for col in insert_cols))
    return int(cur.rowcount or 0)


def write_bot_log_db(level: str, message: str) -> None:
    db = None
    cur = None
    try:
        db = get_db()
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


def strip_html(text: str) -> str:
    if not text:
        return ""
    text = html.unescape(text)
    text = re.sub(r"<[^>]+>", " ", text)
    text = re.sub(r"\s+", " ", text)
    return text.strip()


def parse_entry_datetime(entry) -> Optional[datetime]:
    for field in ("published", "updated"):
        raw = getattr(entry, field, None)
        if not raw:
            continue
        try:
            return parsedate_to_datetime(raw)
        except Exception:
            continue
    return None


def make_external_news_id(symbol: str, url: str, title: str) -> str:
    raw = f"{symbol}|{url}|{title}"
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


def classify_market_relevance(symbol: str, title: str, summary: str) -> str:
    haystack = f"{title} {summary}".lower()
    symbol_l = symbol.lower()

    hot_terms = [
        "earnings", "guidance", "sec", "lawsuit", "merger", "acquisition",
        "downgrade", "upgrade", "dividend", "profit warning", "regulation",
        "investigation", "federal reserve", "interest rate", "etf"
    ]

    score = 0
    if symbol_l in haystack:
        score += 2
    for term in hot_terms:
        if term in haystack:
            score += 1

    if score >= 3:
        return "HIGH"
    if score >= 1:
        return "MEDIUM"
    return "LOW"


def classify_sentiment(title: str, summary: str) -> tuple[Optional[float], Optional[str]]:
    haystack = f"{title} {summary}".lower()
    pos_words = ["beat", "strong", "gain", "growth", "upgrade", "record", "surge", "profit"]
    neg_words = ["miss", "weak", "drop", "loss", "downgrade", "lawsuit", "probe", "warning"]

    pos_hits = sum(1 for w in pos_words if w in haystack)
    neg_hits = sum(1 for w in neg_words if w in haystack)

    if pos_hits == 0 and neg_hits == 0:
        return None, None

    score = round((pos_hits - neg_hits) / max(1, (pos_hits + neg_hits)), 4)

    if pos_hits > 0 and neg_hits > 0:
        return score, "MIXED"
    if score > 0:
        return score, "POSITIVE"
    if score < 0:
        return score, "NEGATIVE"
    return 0.0, "NEUTRAL"


def load_assets() -> list[dict]:
    db = None
    cur = None
    try:
        db = get_db()
        cur = db.cursor(dictionary=True)
        cur.execute(
            """
            SELECT
                b.symbol,
                COALESCE(u.display_name, t.display_name, b.symbol) AS display_name,
                LOWER(COALESCE(u.asset_type, t.asset_type, b.asset_type, '')) AS asset_type
            FROM bot_symbols b
            LEFT JOIN tracked_symbols t ON t.symbol = b.symbol
            LEFT JOIN asset_universe u ON u.symbol = b.symbol
            WHERE b.is_active = 1
            ORDER BY b.priority_order ASC, b.symbol ASC
            """
        )
        return cur.fetchall()
    finally:
        if cur is not None:
            cur.close()
        if db is not None and db.is_connected():
            db.close()


def build_news_query(symbol: str, display_name: Optional[str], asset_type: str) -> str:
    if display_name and display_name.strip() and display_name.strip().upper() != symbol.upper():
        left = f'("{symbol}" OR "{display_name.strip()}")'
    else:
        left = f'"{symbol}"'

    if asset_type == "crypto":
        return f"{left} crypto market"
    if asset_type == "etf":
        return f"{left} ETF market"
    return f"{left} stock market"


def fetch_google_news(symbol: str, display_name: Optional[str], asset_type: str) -> list[dict]:
    query = build_news_query(symbol, display_name, asset_type)
    url = f"https://news.google.com/rss/search?q={query.replace(' ', '%20')}&hl=en-US&gl=US&ceid=US:en"
    feed = feedparser.parse(url)

    items: list[dict] = []

    for entry in feed.entries[:MAX_ITEMS_PER_ASSET]:
        title = strip_html(getattr(entry, "title", "") or "")
        summary = strip_html(getattr(entry, "summary", "") or "")
        link = (getattr(entry, "link", "") or "").strip()
        published = parse_entry_datetime(entry)

        if not title:
            continue

        source_name = "Google News"
        if hasattr(entry, "source") and isinstance(entry.source, dict):
            source_name = str(entry.source.get("title") or "Google News")

        sentiment_score, sentiment_label = classify_sentiment(title, summary)

        published_at = (
            published.astimezone(ZoneInfo(APP_TIMEZONE)).strftime("%Y-%m-%d %H:%M:%S")
            if published is not None
            else datetime.now(ZoneInfo(APP_TIMEZONE)).strftime("%Y-%m-%d %H:%M:%S")
        )

        items.append(
            {
                "symbol": symbol,
                "published_at": published_at,
                "title": title[:500],
                "summary": summary[:65000] if summary else None,
                "url": link[:1000] if link else None,
                "source_name": source_name[:255],
                "source_provider": "google_news_rss",
                "language_code": "en",
                "sentiment_score": sentiment_score,
                "sentiment_label": sentiment_label,
                "importance_score": None,
                "market_relevance": classify_market_relevance(symbol, title, summary),
                "external_news_id": make_external_news_id(symbol, link, title),
            }
        )

    return items


def store_news(items: list[dict]) -> int:
    if not items:
        return 0

    db = None
    cur = None
    processed = 0

    try:
        db = get_db()
        cur = db.cursor()

        for item in items:
            processed += upsert_asset_news(cur, item)

        db.commit()
        return processed
    except Exception:
        if db is not None:
            db.rollback()
        raise
    finally:
        if cur is not None:
            cur.close()
        if db is not None and db.is_connected():
            db.close()


def main():
    run_started = time.time()

    total_assets = 0
    total_processed = 0
    assets_ok = 0
    assets_error = 0
    assets_empty = 0

    log_bot("START", "run=begin")

    try:
        assets = load_assets()
        if not assets:
            duration = round(time.time() - run_started, 2)
            log_bot("END", f"status=STOPPED reason=GEEN_ACTIEVE_ASSETS duration={duration}s", level="WARNING")
            return

        total_assets = len(assets)
        log_bot("INFO", f"assets={total_assets} max_items_per_asset={MAX_ITEMS_PER_ASSET} sleep={REQUEST_SLEEP_SECONDS}s")

        for asset in assets:
            symbol = str(asset.get("symbol") or "").upper().strip()
            display_name = asset.get("display_name")
            asset_type = str(asset.get("asset_type") or "").lower().strip()

            if not symbol:
                continue

            try:
                log_bot(
                    "INFO",
                    f"symbol={symbol} status=START asset_type={asset_type or 'unknown'}"
                )

                news_items = fetch_google_news(symbol, display_name, asset_type)
                fetched_count = len(news_items)

                if fetched_count == 0:
                    assets_empty += 1
                    assets_ok += 1
                    log_bot(
                        "INFO",
                        f"symbol={symbol} status=OK outcome=NO_ITEMS fetched=0 stored=0"
                    )
                    time.sleep(REQUEST_SLEEP_SECONDS)
                    continue

                processed = store_news(news_items)
                total_processed += processed
                assets_ok += 1

                log_bot(
                    "INFO",
                    f"symbol={symbol} status=OK outcome=STORED fetched={fetched_count} stored={processed}"
                )

            except Exception as exc:
                assets_error += 1
                log_bot(
                    "ERROR",
                    f"symbol={symbol} status=FAILED error={exc}",
                    level="ERROR"
                )

            time.sleep(REQUEST_SLEEP_SECONDS)

        duration = round(time.time() - run_started, 2)

        if assets_error > 0:
            log_bot(
                "END",
                f"status=PARTIAL duration={duration}s assets={total_assets} assets_ok={assets_ok} assets_empty={assets_empty} assets_error={assets_error} stored_total={total_processed}",
                level="WARNING"
            )
        else:
            log_bot(
                "END",
                f"status=OK duration={duration}s assets={total_assets} assets_ok={assets_ok} assets_empty={assets_empty} assets_error=0 stored_total={total_processed}"
            )

    except Exception as exc:
        duration = round(time.time() - run_started, 2)
        log_bot("ERROR", f"status=FAILED error={exc}", level="ERROR")
        log_bot("END", f"status=FAILED duration={duration}s", level="ERROR")


if __name__ == "__main__":
    main()