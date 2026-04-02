#!/usr/bin/env python3
from __future__ import annotations

import logging
import os
import shutil
import time
from datetime import datetime, timedelta
from logging.handlers import RotatingFileHandler
from pathlib import Path
from typing import Optional
from zoneinfo import ZoneInfo

import mysql.connector

from db_config import DB_CONFIG


BOT_NAME = "CLEANUP"
APP_TIMEZONE = "Europe/Amsterdam"
LOG_FILE = "/home/hans/trading_project/cleanup.log"
LOG_LEVEL = logging.INFO
PROJECT_DIR = Path("/home/hans/trading_project")
FILE_LOG_RETENTION_DAYS = 7

DB_RETENTION_RULES = [
    ("bot_logs", "created_at", 7),
    ("strategy_runs", "created_at", 7),
    ("asset_signal_log", "signal_time", 7),
    ("asset_news", "published_at", 30),
]

LOG_FILES_TO_ROTATE = [
    "breakout_strategy.log",
    "cron.log",
    "news_sync.log",
    "cleanup.log",
]

os.environ["TZ"] = APP_TIMEZONE
try:
    time.tzset()
except AttributeError:
    pass

logger = logging.getLogger("cleanup_retention")
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


def cleanup_database() -> tuple[int, int]:
    db = None
    cur = None
    total_deleted = 0
    error_count = 0

    try:
        db = get_db()
        cur = db.cursor()

        for table_name, date_column, retention_days in DB_RETENTION_RULES:
            try:
                cols = get_table_columns(cur, table_name)
                if date_column.lower() not in cols:
                    error_count += 1
                    log_bot(
                        "WARNING",
                        f"component=database table={table_name} status=SKIP reason=DATUMKOLOM_ONTBREEKT column={date_column}",
                        level="WARNING",
                    )
                    continue

                sql = f"DELETE FROM {table_name} WHERE {date_column} < (NOW() - INTERVAL %s DAY)"
                cur.execute(sql, (retention_days,))
                deleted = int(cur.rowcount or 0)
                total_deleted += deleted

                log_bot(
                    "INFO",
                    f"component=database table={table_name} status=OK deleted={deleted} retention_days={retention_days}"
                )
            except Exception as exc:
                error_count += 1
                log_bot(
                    "ERROR",
                    f"component=database table={table_name} status=FAILED error={exc}",
                    level="ERROR",
                )

        db.commit()
        return total_deleted, error_count

    except Exception:
        if db is not None:
            db.rollback()
        raise

    finally:
        if cur is not None:
            cur.close()
        if db is not None and db.is_connected():
            db.close()


def rotate_single_log(log_name: str, for_date: datetime) -> tuple[str, str]:
    source = PROJECT_DIR / log_name
    date_part = for_date.strftime("%Y-%m-%d")
    stem = source.stem
    suffix = source.suffix or ".log"
    rotated = PROJECT_DIR / f"{stem}-{date_part}{suffix}"

    if not source.exists():
        return "SKIP", "bronbestand_ontbreekt"

    if source.stat().st_size == 0:
        return "SKIP", "leeg_niets_te_roteren"

    if rotated.exists():
        with source.open("rb") as src, rotated.open("ab") as dst:
            dst.write(src.read())
        source.write_text("", encoding="utf-8")
        return "OK", f"toegevoegd_aan={rotated.name}"

    shutil.copy2(source, rotated)
    source.write_text("", encoding="utf-8")
    return "OK", f"geroteerd_naar={rotated.name}"


def cleanup_old_rotated_logs() -> tuple[int, int]:
    cutoff = datetime.now(ZoneInfo(APP_TIMEZONE)).date() - timedelta(days=FILE_LOG_RETENTION_DAYS)
    removed = 0
    errors = 0

    for path in PROJECT_DIR.glob("*.log"):
        if path.name in LOG_FILES_TO_ROTATE:
            continue

        file_date = None
        try:
            base = path.name[:-4]
            date_part = base.rsplit("-", 1)[1]
            file_date = datetime.strptime(date_part, "%Y-%m-%d").date()
        except Exception:
            file_date = None

        if file_date is None:
            continue

        if file_date < cutoff:
            try:
                path.unlink()
                removed += 1
                log_bot(
                    "INFO",
                    f"component=file_cleanup file={path.name} status=OK action=DELETE_OLD_ROTATED_LOG"
                )
            except Exception as exc:
                errors += 1
                log_bot(
                    "ERROR",
                    f"component=file_cleanup file={path.name} status=FAILED error={exc}",
                    level="ERROR",
                )

    return removed, errors


def rotate_logs() -> tuple[int, int, int, int]:
    rotate_date = datetime.now(ZoneInfo(APP_TIMEZONE)) - timedelta(days=1)

    rotated_ok = 0
    rotated_skipped = 0
    rotated_errors = 0

    for log_name in LOG_FILES_TO_ROTATE:
        try:
            status, detail = rotate_single_log(log_name, rotate_date)

            if status == "OK":
                rotated_ok += 1
                log_bot(
                    "INFO",
                    f"component=log_rotation file={log_name} status=OK detail={detail}"
                )
            else:
                rotated_skipped += 1
                log_bot(
                    "INFO",
                    f"component=log_rotation file={log_name} status=SKIP detail={detail}"
                )

        except Exception as exc:
            rotated_errors += 1
            log_bot(
                "ERROR",
                f"component=log_rotation file={log_name} status=FAILED error={exc}",
                level="ERROR",
            )

    removed_old, old_cleanup_errors = cleanup_old_rotated_logs()

    log_bot(
        "INFO",
        f"component=log_rotation_old_cleanup status=OK removed_old={removed_old} retention_days={FILE_LOG_RETENTION_DAYS}"
    )

    return rotated_ok, rotated_skipped, removed_old, rotated_errors + old_cleanup_errors


def main():
    run_started = time.time()

    db_deleted = 0
    db_errors = 0
    rotated_ok = 0
    rotated_skipped = 0
    removed_old = 0
    rotation_errors = 0

    log_bot("START", "run=begin")

    try:
        try:
            db_deleted, db_errors = cleanup_database()
            log_bot(
                "INFO",
                f"component=database_summary status=OK deleted_total={db_deleted} errors={db_errors}"
            )
        except Exception as exc:
            db_errors += 1
            log_bot(
                "ERROR",
                f"component=database_summary status=FAILED error={exc}",
                level="ERROR",
            )

        try:
            rotated_ok, rotated_skipped, removed_old, rotation_errors = rotate_logs()
            log_bot(
                "INFO",
                f"component=log_rotation_summary status=OK rotated_ok={rotated_ok} rotated_skipped={rotated_skipped} removed_old={removed_old} errors={rotation_errors}"
            )
        except Exception as exc:
            rotation_errors += 1
            log_bot(
                "ERROR",
                f"component=log_rotation_summary status=FAILED error={exc}",
                level="ERROR",
            )

        total_errors = db_errors + rotation_errors
        duration = round(time.time() - run_started, 2)

        if total_errors > 0:
            log_bot(
                "END",
                f"status=PARTIAL duration={duration}s db_deleted={db_deleted} rotated_ok={rotated_ok} rotated_skipped={rotated_skipped} removed_old={removed_old} errors={total_errors}",
                level="WARNING",
            )
        else:
            log_bot(
                "END",
                f"status=OK duration={duration}s db_deleted={db_deleted} rotated_ok={rotated_ok} rotated_skipped={rotated_skipped} removed_old={removed_old} errors=0"
            )

    except Exception as exc:
        duration = round(time.time() - run_started, 2)
        log_bot("ERROR", f"status=FAILED error={exc}", level="ERROR")
        log_bot("END", f"status=FAILED duration={duration}s", level="ERROR")


if __name__ == "__main__":
    main()