#!/usr/bin/env python3
from __future__ import annotations

import logging
import os
from datetime import datetime

import mysql.connector
from mysql.connector import Error

from db_config import DB_CONFIG

LOG_LEVEL = os.getenv("TRADING_LOG_LEVEL", "INFO").upper()
logging.basicConfig(
    level=getattr(logging, LOG_LEVEL, logging.INFO),
    format="%(asctime)s %(levelname)s %(message)s",
)
logger = logging.getLogger("trading_py.breakout")


def get_connection():
    return mysql.connector.connect(**DB_CONFIG.as_mysql_kwargs())


def ensure_strategy_run_table(cur) -> None:
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS strategy_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            symbol VARCHAR(40) NOT NULL,
            action_taken VARCHAR(40) NOT NULL,
            note VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_strategy_runs_symbol_created (symbol, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """
    )


def load_active_symbols(cur) -> list[str]:
    cur.execute(
        "SELECT symbol FROM bot_symbols WHERE is_active = 1 ORDER BY priority_order ASC, symbol ASC"
    )
    return [row[0] for row in cur.fetchall()]


def load_bot_settings(cur) -> dict:
    cur.execute(
        "SELECT bot_enabled, paper_mode, trade_enabled, breakout_window, amount_per_trade_eur, max_open_positions FROM bot_settings ORDER BY id ASC LIMIT 1"
    )
    row = cur.fetchone()
    if not row:
        raise RuntimeError("Geen bot_settings record gevonden.")
    keys = [
        "bot_enabled",
        "paper_mode",
        "trade_enabled",
        "breakout_window",
        "amount_per_trade_eur",
        "max_open_positions",
    ]
    return dict(zip(keys, row))


def write_run(cur, symbol: str, action_taken: str, note: str | None = None) -> None:
    cur.execute(
        "INSERT INTO strategy_runs (symbol, action_taken, note, created_at) VALUES (%s, %s, %s, %s)",
        (symbol, action_taken, note, datetime.now()),
    )


def main() -> int:
    try:
        with get_connection() as conn:
            with conn.cursor() as cur:
                ensure_strategy_run_table(cur)
                settings = load_bot_settings(cur)
                if int(settings.get("bot_enabled", 0)) != 1:
                    logger.info("Bot staat uit. Geen verwerking uitgevoerd.")
                    conn.commit()
                    return 0

                symbols = load_active_symbols(cur)
                if not symbols:
                    logger.info("Geen actieve symbolen gevonden.")
                    conn.commit()
                    return 0

                for symbol in symbols:
                    write_run(cur, symbol, "NO_ACTION", "Refactor basisversie: symbool verwerkt zonder orderplaatsing")
                conn.commit()
                logger.info("Strategie-run voltooid voor %s symbolen.", len(symbols))
        return 0
    except (Error, RuntimeError) as exc:
        logger.exception("breakout_strategy mislukt: %s", exc)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
