#!/usr/bin/env python3
from __future__ import annotations

import csv
import logging
import os
import time
from datetime import datetime
from logging.handlers import RotatingFileHandler
from pathlib import Path
from typing import Iterable, Optional
from zoneinfo import ZoneInfo

import mysql.connector
from mysql.connector import Error

from db_config import DB_CONFIG


BOT_NAME = "ASSET_SYNC"
APP_TIMEZONE = "Europe/Amsterdam"
BASE_DIR = Path(__file__).resolve().parent
LOG_FILE = BASE_DIR / "asset_sync.log"
LOG_LEVEL = os.getenv("TRADING_LOG_LEVEL", "INFO").upper()

DEFAULT_CSV = Path(
    os.getenv("TRADING_ASSET_CSV", str(BASE_DIR / "asset_universe_seed.csv"))
).resolve()

DEFAULT_DIR = Path(
    os.getenv("TRADING_ASSET_DIR", str(BASE_DIR / "seed_exchanges"))
).resolve()

os.environ["TZ"] = APP_TIMEZONE
try:
    time.tzset()
except AttributeError:
    pass

logger = logging.getLogger("trading_py.sync_assets")
logger.setLevel(getattr(logging, LOG_LEVEL, logging.INFO))
logger.handlers.clear()


class AmsterdamFormatter(logging.Formatter):
    def formatTime(self, record, datefmt=None):
        dt = datetime.fromtimestamp(record.created, tz=ZoneInfo(APP_TIMEZONE))
        if datefmt:
            return dt.strftime(datefmt)
        return dt.strftime("%Y-%m-%d %H:%M:%S")


formatter = AmsterdamFormatter("%(asctime)s | %(levelname)s | %(message)s")

file_handler = RotatingFileHandler(str(LOG_FILE), maxBytes=2_000_000, backupCount=5)
file_handler.setFormatter(formatter)
logger.addHandler(file_handler)

stream_handler = logging.StreamHandler()
stream_handler.setFormatter(formatter)
logger.addHandler(stream_handler)

_TABLE_COLUMNS_CACHE: dict[str, set[str]] = {}


def get_connection():
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
        db = get_connection()
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


def ensure_tables(cur) -> None:
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS asset_universe (
            id INT AUTO_INCREMENT PRIMARY KEY,
            symbol VARCHAR(40) NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            asset_type VARCHAR(40) NOT NULL,
            exchange_name VARCHAR(255) NULL,
            currency VARCHAR(16) NULL,
            provider VARCHAR(80) NOT NULL DEFAULT 'seed',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            search_text TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_asset_universe_symbol_provider (symbol, provider),
            KEY idx_asset_universe_status (status),
            KEY idx_asset_universe_type (asset_type),
            KEY idx_asset_universe_exchange (exchange_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        """
    )


def normalize_symbol(symbol: str) -> str:
    return (symbol or "").strip().upper()


def discover_input_files() -> list[Path]:
    files: list[Path] = []

    if DEFAULT_DIR.exists() and DEFAULT_DIR.is_dir():
        files.extend(sorted(DEFAULT_DIR.glob("*.csv")))

    if DEFAULT_CSV.exists() and DEFAULT_CSV.is_file():
        files.append(DEFAULT_CSV)

    unique_files: list[Path] = []
    seen: set[str] = set()

    for file in files:
        resolved = str(file.resolve())
        if resolved not in seen:
            seen.add(resolved)
            unique_files.append(file.resolve())

    if not unique_files:
        raise FileNotFoundError(
            f"Geen input gevonden. Verwacht CSV-bestand op {DEFAULT_CSV} of map {DEFAULT_DIR}"
        )

    return unique_files


def load_csv_rows(csv_path: Path) -> list[dict]:
    if not csv_path.exists():
        raise FileNotFoundError(f"CSV bestand niet gevonden: {csv_path}")

    rows: list[dict] = []
    with csv_path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            clean = {str(k).strip(): (v or "").strip() for k, v in row.items()}
            clean["symbol"] = normalize_symbol(clean.get("symbol", ""))
            rows.append(clean)

    return rows


def load_all_rows(files: Iterable[Path]) -> tuple[list[dict], int]:
    combined: list[dict] = []
    raw_count = 0

    for file in files:
        file_rows = load_csv_rows(file)
        raw_count += len(file_rows)
        combined.extend(file_rows)
        log_bot("INFO", f"file={file.name} status=LOADED rows={len(file_rows)}", db_log=True)

    deduped: list[dict] = []
    seen: set[tuple[str, str]] = set()

    for row in combined:
        symbol = normalize_symbol(row.get("symbol", ""))
        provider = (row.get("provider") or "seed").strip().lower()
        if not symbol:
            deduped.append(row)
            continue

        key = (symbol, provider)
        if key in seen:
            continue

        seen.add(key)
        row["symbol"] = symbol
        row["provider"] = provider
        deduped.append(row)

    return deduped, raw_count


def upsert_rows(cur, rows: list[dict]) -> tuple[int, int]:
    processed = 0
    skipped = 0

    for row in rows:
        symbol = normalize_symbol(row.get("symbol", ""))
        if not symbol:
            skipped += 1
            continue

        cur.execute(
            """
            INSERT INTO asset_universe
                (symbol, display_name, asset_type, exchange_name, currency, provider, status, search_text)
            VALUES
                (%s, %s, %s, %s, %s, %s, 'active', %s)
            ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                asset_type = VALUES(asset_type),
                exchange_name = VALUES(exchange_name),
                currency = VALUES(currency),
                search_text = VALUES(search_text),
                status = 'active',
                updated_at = CURRENT_TIMESTAMP
            """,
            (
                symbol,
                row.get("display_name", symbol),
                (row.get("asset_type") or "stock").strip().lower(),
                row.get("exchange_name") or None,
                row.get("currency") or None,
                (row.get("provider") or "seed").strip().lower(),
                row.get("search_text") or row.get("keywords") or None,
            ),
        )
        processed += 1

    return processed, skipped


def main() -> int:
    run_started = time.time()

    files_count = 0
    rows_loaded_raw = 0
    rows_loaded_unique = 0
    rows_processed = 0
    rows_skipped = 0

    log_bot(
        "START",
        f"csv={DEFAULT_CSV} dir={DEFAULT_DIR} cwd={Path.cwd()}"
    )

    try:
        files = discover_input_files()
        files_count = len(files)

        log_bot("INFO", f"files_detected={files_count}")

        rows, rows_loaded_raw = load_all_rows(files)
        rows_loaded_unique = len(rows)

        log_bot(
            "INFO",
            f"rows_loaded_raw={rows_loaded_raw} rows_loaded_unique={rows_loaded_unique}"
        )

        with get_connection() as conn:
            with conn.cursor() as cur:
                ensure_tables(cur)
                rows_processed, rows_skipped = upsert_rows(cur, rows)
                conn.commit()

        duration = round(time.time() - run_started, 2)

        log_bot(
            "END",
            f"status=OK duration={duration}s files={files_count} rows_loaded_raw={rows_loaded_raw} rows_loaded_unique={rows_loaded_unique} rows_processed={rows_processed} rows_skipped={rows_skipped}"
        )
        return 0

    except (Error, FileNotFoundError, RuntimeError) as exc:
        duration = round(time.time() - run_started, 2)
        log_bot("ERROR", f"status=FAILED error={exc}", level="ERROR")
        log_bot("END", f"status=FAILED duration={duration}s", level="ERROR")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())