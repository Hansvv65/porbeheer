#!/usr/bin/env python3
from __future__ import annotations

import logging
import shutil
from datetime import datetime, timedelta
from pathlib import Path

import mysql.connector

from db_config import DB_CONFIG

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s | %(levelname)s | %(message)s",
)
logger = logging.getLogger(__name__)

RETENTION_RULES = [
    ("bot_logs", "created_at", 7),
    ("strategy_runs", "created_at", 7),
    ("asset_signal_log", "signal_time", 7),
    ("asset_news", "published_at", 30),
]

PROJECT_DIR = Path("/home/hans/trading_project")
FILE_LOG_RETENTION_DAYS = 7

LOG_FILES_TO_ROTATE = [
    "breakout_strategy.log",
    "cron.log",
    "news_sync.log",
    "cleanup.log",
]


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


def cleanup_database() -> int:
    db = get_db()
    cur = db.cursor()

    total_deleted = 0

    try:
        for table_name, date_column, retention_days in RETENTION_RULES:
            sql = f"""
                DELETE FROM {table_name}
                WHERE {date_column} < (NOW() - INTERVAL %s DAY)
            """
            try:
                cur.execute(sql, (retention_days,))
                deleted = cur.rowcount
                total_deleted += deleted
                logger.info(
                    "%s: %s record(s) verwijderd ouder dan %s dagen",
                    table_name,
                    deleted,
                    retention_days,
                )
            except Exception as exc:
                logger.warning("cleanup skip %s: %s", table_name, exc)
                log_bot("WARN", f"CLEANUP skip {table_name}: {exc}")

        db.commit()
        return total_deleted
    except Exception:
        db.rollback()
        raise
    finally:
        cur.close()
        db.close()


def rotate_single_log(log_name: str, for_date: datetime) -> str:
    source = PROJECT_DIR / log_name
    date_part = for_date.strftime("%Y-%m-%d")
    stem = source.stem
    suffix = source.suffix or ".log"
    rotated = PROJECT_DIR / f"{stem}-{date_part}{suffix}"

    if not source.exists():
        return f"{log_name}: bronbestand ontbreekt"

    if source.stat().st_size == 0:
        return f"{log_name}: leeg, niets te roteren"

    if rotated.exists():
        with source.open("rb") as src, rotated.open("ab") as dst:
            dst.write(src.read())
        source.write_text("", encoding="utf-8")
        return f"{log_name}: toegevoegd aan bestaand {rotated.name}"

    shutil.copy2(source, rotated)
    source.write_text("", encoding="utf-8")
    return f"{log_name}: geroteerd naar {roted_name(rotated)}"


def roted_name(path: Path) -> str:
    return path.name


def cleanup_old_rotated_logs() -> int:
    cutoff = datetime.now() - timedelta(days=FILE_LOG_RETENTION_DAYS)
    removed = 0

    for path in PROJECT_DIR.glob("*.log"):
        name = path.name

        # actieve logfiles niet verwijderen
        if name in LOG_FILES_TO_ROTATE:
            continue

        # verwacht bv breakout_strategy-2026-03-26.log
        try:
            base = name[:-4]  # strip .log
            date_part = base.rsplit("-", 1)[1]
            file_date = datetime.strptime(date_part, "%Y-%m-%d")
        except Exception:
            continue

        if file_date < cutoff:
            try:
                path.unlink()
                removed += 1
                logger.info("Oude log verwijderd: %s", path.name)
            except Exception as exc:
                logger.warning("Kon oude log niet verwijderen %s: %s", path.name, exc)

    return removed


def rotate_logs() -> None:
    yesterday = datetime.now() - timedelta(days=1)

    for log_name in LOG_FILES_TO_ROTATE:
        try:
            result = rotate_single_log(log_name, yesterday)
            logger.info(result)
            log_bot("INFO", f"CLEANUP logrotatie: {result}")
        except Exception as exc:
            logger.warning("Logrotatie fout voor %s: %s", log_name, exc)
            log_bot("ERROR", f"CLEANUP logrotatie fout {log_name}: {exc}")

    removed = cleanup_old_rotated_logs()
    logger.info("Oude geroteerde logs verwijderd: %s", removed)
    log_bot("INFO", f"CLEANUP oude geroteerde logs verwijderd: {removed}")


def main():
    logger.info("CLEANUP gestart")
    log_bot("INFO", "CLEANUP gestart")

    try:
        deleted = cleanup_database()
        logger.info("Database cleanup klaar, totaal verwijderd: %s", deleted)
        log_bot("INFO", f"CLEANUP database klaar: totaal verwijderd {deleted}")
    except Exception as exc:
        logger.exception("Database cleanup fout")
        log_bot("ERROR", f"CLEANUP database fout: {exc}")

    try:
        rotate_logs()
    except Exception as exc:
        logger.exception("Logrotatie fout")
        log_bot("ERROR", f"CLEANUP logrotatie fout: {exc}")

    logger.info("CLEANUP afgerond")
    log_bot("INFO", "CLEANUP afgerond")


if __name__ == "__main__":
    main()