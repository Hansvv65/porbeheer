#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import html
import logging
import re
import time
from email.utils import parsedate_to_datetime

import feedparser
import mysql.connector

from db_config import DB_CONFIG

LOG_LEVEL = logging.INFO
MAX_ITEMS_PER_ASSET = 8
REQUEST_SLEEP_SECONDS = 1.0

logging.basicConfig(
    level=LOG_LEVEL,
    format="%(asctime)s | %(levelname)s | %(message)s",
)
logger = logging.getLogger(__name__)


def get_db():
    return mysql.connector.connect(**DB_CONFIG.as_mysql_kwargs())


def log_bot(level: str, message: str) -> None:
    db = None
    cur = None
    try:
        db = get_db()
        cur = db.cursor()
        cur.execute(
            """
            INSERT INTO bot_logs (level, message, created_at)
            VALUES (%s, %s, NOW())
            """,
            (level.upper().strip(), message[:1000]),
        )
        db.commit()
    except Exception as exc:
        logger.warning("Kon niet naar bot_logs schrijven: %s", exc)
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


def strip_html(text: str) -> str:
    if not text:
        return ""
    text = html.unescape(text)
    text = re.sub(r"<[^>]+>", " ", text)
    text = re.sub(r"\s+", " ", text)
    return text.strip()


def parse_date(entry):
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


def load_assets() -> list[dict]:
    db = get_db()
    cur = db.cursor(dictionary=True)
    try:
        cur.execute(
            """
            SELECT symbol
            FROM bot_symbols
            WHERE is_active = 1
            ORDER BY priority_order ASC, symbol ASC
            """
        )
        return cur.fetchall()
    finally:
        cur.close()
        db.close()


def fetch_google_news(symbol: str) -> list[dict]:
    url = f"https://news.google.com/rss/search?q={symbol}%20stock&hl=en-US&gl=US&ceid=US:en"
    feed = feedparser.parse(url)

    items: list[dict] = []

    for entry in feed.entries[:MAX_ITEMS_PER_ASSET]:
        title = strip_html(getattr(entry, "title", "") or "")
        summary = strip_html(getattr(entry, "summary", "") or "")
        link = getattr(entry, "link", "") or ""
        published = parse_date(entry)

        if not title:
            continue

        items.append(
            {
                "symbol": symbol,
                "published_at": published.strftime("%Y-%m-%d %H:%M:%S") if published else None,
                "title": title[:500],
                "summary": summary[:5000] if summary else None,
                "url": link[:1000] if link else None,
                "external_news_id": make_external_news_id(symbol, link, title),
            }
        )

    return items


def store_news(items: list[dict]) -> int:
    if not items:
        return 0

    db = get_db()
    cur = db.cursor()

    processed = 0

    try:
        sql = """
            INSERT INTO asset_news
            (
                symbol,
                published_at,
                title,
                summary,
                url,
                source_provider,
                external_news_id
            )
            VALUES (%s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                summary = VALUES(summary),
                url = VALUES(url)
        """

        for item in items:
            cur.execute(
                sql,
                (
                    item["symbol"],
                    item["published_at"],
                    item["title"],
                    item["summary"],
                    item["url"],
                    "google_news",
                    item["external_news_id"],
                ),
            )
            processed += cur.rowcount

        db.commit()
        return processed
    except Exception:
        db.rollback()
        raise
    finally:
        cur.close()
        db.close()


def main():
    logger.info("NEWS_SYNC gestart")
    log_bot("INFO", "NEWS_SYNC gestart")

    assets = load_assets()
    total = 0

    if not assets:
        logger.warning("Geen actieve assets gevonden")
        log_bot("WARN", "NEWS_SYNC: geen actieve assets gevonden")
        return

    for asset in assets:
        symbol = (asset.get("symbol") or "").strip().upper()
        if not symbol:
            continue

        try:
            logger.info("Nieuws ophalen voor %s", symbol)
            news = fetch_google_news(symbol)
            processed = store_news(news)
            total += processed

            logger.info("%s: %s rows verwerkt", symbol, processed)
            log_bot("INFO", f"NEWS_SYNC {symbol}: {processed} rows verwerkt")
        except Exception as exc:
            logger.exception("Fout bij %s", symbol)
            log_bot("ERROR", f"NEWS_SYNC {symbol} fout: {exc}")

        time.sleep(REQUEST_SLEEP_SECONDS)

    logger.info("NEWS_SYNC afgerond, totaal verwerkt: %s", total)
    log_bot("INFO", f"NEWS_SYNC afgerond: totaal verwerkt {total}")


if __name__ == "__main__":
    main()