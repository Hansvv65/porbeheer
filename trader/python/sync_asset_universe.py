#!/usr/bin/env python3
from __future__ import annotations

import csv
import logging
import os
from pathlib import Path

import mysql.connector
from mysql.connector import Error

from db_config import DB_CONFIG

LOG_LEVEL = os.getenv("TRADING_LOG_LEVEL", "INFO").upper()
logging.basicConfig(level=getattr(logging, LOG_LEVEL, logging.INFO), format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger("trading_py.sync_assets")

DEFAULT_CSV = Path(os.getenv("TRADING_ASSET_CSV", "./asset_universe_seed.csv"))


def get_connection():
    return mysql.connector.connect(**DB_CONFIG.as_mysql_kwargs())


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
            KEY idx_asset_universe_type (asset_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """
    )


def load_rows(csv_path: Path) -> list[dict]:
    if not csv_path.exists():
        raise FileNotFoundError(f"CSV bestand niet gevonden: {csv_path}")
    with csv_path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        rows = []
        for row in reader:
            rows.append({k: (v or "").strip() for k, v in row.items()})
        return rows


def upsert_rows(cur, rows: list[dict]) -> int:
    count = 0
    for row in rows:
        symbol = row.get("symbol", "").upper()
        if not symbol:
            continue
        cur.execute(
            """
            INSERT INTO asset_universe (symbol, display_name, asset_type, exchange_name, currency, provider, status, search_text)
            VALUES (%s, %s, %s, %s, %s, %s, 'active', %s)
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
                row.get("asset_type", "stock"),
                row.get("exchange_name") or None,
                row.get("currency") or None,
                row.get("provider") or "seed",
                row.get("search_text") or row.get("keywords") or None,
            ),
        )
        count += 1
    return count


def main() -> int:
    try:
        rows = load_rows(DEFAULT_CSV)
        with get_connection() as conn:
            with conn.cursor() as cur:
                ensure_tables(cur)
                changed = upsert_rows(cur, rows)
                conn.commit()
        logger.info("Upserted rows: %s", changed)
        return 0
    except (Error, FileNotFoundError) as exc:
        logger.exception("sync_asset_universe mislukt: %s", exc)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
