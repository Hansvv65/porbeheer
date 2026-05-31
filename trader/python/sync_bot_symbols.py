#!/usr/bin/env python3
from __future__ import annotations

import logging
import os
import re
import sys
import time
from datetime import datetime
from logging.handlers import RotatingFileHandler
from typing import Optional, Dict, Any, List
from zoneinfo import ZoneInfo

import mysql.connector
import requests
import yfinance as yf

from db_config import DB_CONFIG

BOT_NAME = "SYNC_BOT_SYMBOLS"
LOG_FILE = "/home/hans/trading_project/sync_bot_symbols.log"
LOG_LEVEL = logging.INFO
APP_TIMEZONE = "Europe/Amsterdam"

os.environ["TZ"] = APP_TIMEZONE
try:
    time.tzset()
except AttributeError:
    pass

logger = logging.getLogger("sync_bot_symbols")
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

stream_handler = logging.StreamHandler(sys.stdout)
stream_handler.setFormatter(formatter)
logger.addHandler(stream_handler)

_TABLE_COLUMNS_CACHE: Dict[str, set[str]] = {}

# ============================================================
# NIEUWE, LAGERE LIQUIDITEITSCRITERIA (voor volatielere assets)
# ============================================================
CRITERIA = {
    "stocks": {
        "min_market_cap": 250_000_000,      # €250 miljoen (was 1 miljard)
        "min_avg_volume": 200_000,          # 200k stuks (was 500k)
    },
    "etf": {
        "min_avg_volume": 50_000,           # 50k stuks
    },
    "crypto": {
        "min_market_cap": 10_000_000,       # €10 miljoen (was 50 miljoen)
        "min_volume_24h": 500_000,          # €500k (was 1 miljoen)
        "volume_to_market_cap_min": 0.01,   # 1% (was 2%)
        "volume_to_market_cap_max": 0.20,   # 20% (was 10%)
        "max_volatility_pct": 15.0,         # Maximale dagelijkse volatiliteit 15% (optioneel)
    }
}

# ============================================================
# DATABASE HELPERS (inclusief logging tabel)
# ============================================================
def get_db_connection():
    return mysql.connector.connect(**DB_CONFIG.as_mysql_kwargs())


def get_table_columns(cur, table_name: str) -> set[str]:
    cache_key = table_name.lower()
    if cache_key in _TABLE_COLUMNS_CACHE:
        return _TABLE_COLUMNS_CACHE[cache_key]
    cur.execute(f"SHOW COLUMNS FROM {table_name}")
    cols = {str(row[0]).lower() for row in cur.fetchall()}
    _TABLE_COLUMNS_CACHE[cache_key] = cols
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
    return cur.lastrowid


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


def log_asset_action(cur, symbol: str, action: str, reason: str, asset_type: str = None) -> None:
    """Schrijft een regel naar bot_symbols_log (database)."""
    try:
        cur.execute("""
            INSERT INTO bot_symbols_log (symbol, action, reason, asset_type)
            VALUES (%s, %s, %s, %s)
        """, (symbol, action, reason[:500], asset_type))
    except Exception as e:
        logger.error(f"Kon asset actie niet loggen voor {symbol}: {e}")


def fetch_all_dict(cur, sql: str, params=()):
    cur.execute(sql, params)
    rows = cur.fetchall()
    cols = [d[0] for d in cur.description]
    return [dict(zip(cols, row)) for row in rows]


def fetch_one_dict(cur, sql: str, params=()):
    cur.execute(sql, params)
    row = cur.fetchone()
    if row is None:
        return None
    cols = [d[0] for d in cur.description]
    return dict(zip(cols, row))


# ============================================================
# SYMBOOL VALIDATIE
# ============================================================
def is_valid_symbol(symbol: str, asset_type: str) -> bool:
    if not symbol or len(symbol) > 30:
        return False
    if symbol.startswith('^'):
        return False
    # Sluit futures en Chinese extensies uit
    if symbol.endswith('=F') or '.SS' in symbol or '.SZ' in symbol:
        return False
    if asset_type == 'crypto':
        return bool(re.match(r'^[a-zA-Z0-9$_\-\.]+$', symbol))
    else:
        return bool(re.match(r'^[A-Z0-9\.\-]+$', symbol, re.IGNORECASE))

# ============================================================
# MARKTDATA OPHALEN (BULK VOOR CRYPTO)
# ============================================================
def get_crypto_market_data_bulk(symbols: List[str]) -> Dict[str, dict]:
    """Haalt marktdata voor meerdere crypto's in één API call."""
    if not symbols:
        return {}
    try:
        url = "https://api.coingecko.com/api/v3/coins/markets"
        params = {
            "vs_currency": "usd",
            "order": "market_cap_desc",
            "per_page": 250,
            "page": 1,
            "sparkline": "false",
            "price_change_percentage": "24h"
        }
        headers = {"User-Agent": "Mozilla/5.0"}
        response = requests.get(url, headers=headers, params=params, timeout=30)
        if response.status_code == 200:
            data = response.json()
            result = {}
            for coin in data:
                coin_symbol = coin.get("symbol", "").upper()
                if coin_symbol in symbols:
                    market_cap = coin.get("market_cap")
                    volume_24h = coin.get("total_volume")
                    if market_cap and volume_24h and market_cap > 0:
                        ratio = volume_24h / market_cap
                        price_change = coin.get("price_change_percentage_24h", 0)
                        # Bereken absolute volatiliteit (absoluut percentage)
                        volatility = abs(price_change) if price_change is not None else 0
                        result[coin_symbol] = {
                            "market_cap": market_cap,
                            "volume_24h": volume_24h,
                            "volume_to_market_ratio": ratio,
                            "current_price": coin.get("current_price"),
                            "price_change_24h": price_change,
                            "volatility_24h_abs": volatility,
                        }
            return result
        elif response.status_code == 429:
            logger.warning("CoinGecko rate limit (bulk), wacht 10 sec...")
            time.sleep(10)
            return {}
        else:
            logger.warning(f"CoinGecko bulk API error: status {response.status_code}")
            return {}
    except Exception as e:
        logger.error(f"Fout bij bulk ophalen crypto data: {e}")
        return {}


def get_stock_market_data(symbol: str) -> Optional[dict]:
    try:
        ticker = yf.Ticker(symbol)
        info = ticker.info
        if info:
            market_cap = info.get("marketCap")
            avg_volume = info.get("averageVolume")
            # Volatiliteit: gebruik daily return (standaardafwijking) als beschikbaar
            # Yahoo Finance geeft 'regularMarketChangePercent' voor dagelijkse verandering.
            price_change = info.get("regularMarketChangePercent", 0)
            volatility = abs(price_change) if price_change is not None else 0
            if market_cap and avg_volume and market_cap > 0:
                return {
                    "market_cap": market_cap,
                    "avg_volume": avg_volume,
                    "current_price": info.get("regularMarketPrice"),
                    "price_change_24h": price_change,
                    "volatility_24h_abs": volatility,
                    "sector": info.get("sector"),
                    "is_etf": info.get("quoteType") == "ETF"
                }
    except Exception as e:
        logger.error(f"Fout bij ophalen stock data voor {symbol}: {e}")
    return None


# ============================================================
# BETROUWBAARHEID CHECK (met volatiliteitsfilter)
# ============================================================
def is_reliable_stock(market_data: dict) -> tuple[bool, str]:
    is_etf = market_data.get("is_etf", False)
    max_vol = CRITERIA.get("stocks", {}).get("max_volatility_pct")
    vol = market_data.get("volatility_24h_abs", 0)
    if max_vol is not None and vol > max_vol:
        return False, f"Te volatiel: {vol:.2f}% > {max_vol:.1f}%"

    if is_etf:
        min_vol = CRITERIA["etf"]["min_avg_volume"]
        avg_volume = market_data.get("avg_volume", 0)
        if avg_volume >= min_vol:
            return True, f"ETF voldoet: volume {avg_volume:,.0f}"
        return False, f"ETF volume te laag: {avg_volume:,.0f} < {min_vol:,.0f}"
    else:
        min_cap = CRITERIA["stocks"]["min_market_cap"]
        min_vol = CRITERIA["stocks"]["min_avg_volume"]
        cap = market_data.get("market_cap", 0)
        avg_volume = market_data.get("avg_volume", 0)
        if cap < min_cap:
            return False, f"Marktkap €{cap:,.0f} < €{min_cap:,.0f}"
        if avg_volume < min_vol:
            return False, f"Volume {avg_volume:,.0f} < {min_vol:,.0f}"
        return True, f"Aandeel voldoet (€{cap:,.0f}, vol {avg_volume:,.0f})"


def is_reliable_crypto(market_data: dict) -> tuple[bool, str]:
    min_cap = CRITERIA["crypto"]["min_market_cap"]
    min_vol = CRITERIA["crypto"]["min_volume_24h"]
    min_ratio = CRITERIA["crypto"]["volume_to_market_cap_min"]
    max_ratio = CRITERIA["crypto"]["volume_to_market_cap_max"]
    max_vol = CRITERIA["crypto"].get("max_volatility_pct")
    cap = market_data.get("market_cap", 0)
    vol24 = market_data.get("volume_24h", 0)
    ratio = market_data.get("volume_to_market_ratio", 0)
    volatility = market_data.get("volatility_24h_abs", 0)

    if max_vol is not None and volatility > max_vol:
        return False, f"Te volatiel (24u): {volatility:.2f}% > {max_vol:.1f}%"

    if cap < min_cap:
        return False, f"Marktkap €{cap:,.0f} < €{min_cap:,.0f}"
    if vol24 < min_vol:
        return False, f"24u volume €{vol24:,.0f} < €{min_vol:,.0f}"
    if ratio < min_ratio or ratio > max_ratio:
        return False, f"Volume/marktkap ratio {ratio:.2%} (gewenst {min_ratio:.0%}-{max_ratio:.0%})"
    return True, f"Crypto voldoet (€{cap:,.0f}, vol €{vol24:,.0f}, ratio {ratio:.2%})"


# ============================================================
# HOOFDFUNCTIES
# ============================================================
def get_candidates_from_universe() -> list[dict]:
    db = None
    cur = None
    try:
        db = get_db_connection()
        cur = db.cursor()
        candidates = fetch_all_dict(cur, """
            SELECT u.symbol, u.display_name, u.asset_type, u.exchange_name, u.currency
            FROM asset_universe u
            WHERE u.status = 'active'
              AND NOT EXISTS (SELECT 1 FROM bot_symbols b WHERE b.symbol = u.symbol)
            ORDER BY u.symbol
            LIMIT 250
        """)
        return candidates
    finally:
        if cur:
            cur.close()
        if db and db.is_connected():
            db.close()


def add_to_bot_symbols(cur, candidate: dict, reason: str) -> bool:
    try:
        asset_type = candidate['asset_type'].upper()
        db_type = 'STOCK' if asset_type in ('STOCK', 'ETF') else 'CRYPTO'
        cur.execute("""
            INSERT IGNORE INTO bot_symbols (symbol, asset_type, data_symbol, order_symbol, is_active, priority_order)
            VALUES (%s, %s, %s, %s, 1, 999)
        """, (candidate['symbol'], db_type, candidate['symbol'], candidate['symbol']))
        if cur.rowcount > 0:
            log_asset_action(cur, candidate['symbol'], 'ADDED', reason, db_type)
            log_bot("ADDED", f"symbol={candidate['symbol']} type={db_type} reason={reason}")
            return True
        else:
            log_asset_action(cur, candidate['symbol'], 'SKIPPED', 'Bestaat al in bot_symbols', db_type)
            return False
    except Exception as e:
        log_asset_action(cur, candidate['symbol'], 'ERROR', str(e)[:500])
        logger.error(f"Fout bij toevoegen {candidate['symbol']}: {e}")
        return False


def process_crypto_candidates(cur, candidates: list[dict]) -> tuple[int, int, int]:
    crypto_symbols = []
    for cand in candidates:
        if cand['asset_type'].lower() == 'crypto' and is_valid_symbol(cand['symbol'], 'crypto'):
            crypto_symbols.append(cand['symbol'].upper())
    if not crypto_symbols:
        return 0, 0, 0

    market_data = get_crypto_market_data_bulk(crypto_symbols)

    added = skipped = errors = 0
    for cand in candidates:
        symbol = cand['symbol'].upper()
        if cand['asset_type'].lower() != 'crypto':
            continue
        if not is_valid_symbol(symbol, 'crypto'):
            log_asset_action(cur, symbol, 'SKIPPED', 'Ongeldig symbool', 'CRYPTO')
            skipped += 1
            continue

        data = market_data.get(symbol)
        if not data:
            log_asset_action(cur, symbol, 'SKIPPED', 'Geen marktdata (niet in CoinGecko top250)', 'CRYPTO')
            skipped += 1
            continue

        ok, reason = is_reliable_crypto(data)
        if ok:
            if add_to_bot_symbols(cur, cand, reason):
                added += 1
                cur.connection.commit()
            else:
                skipped += 1
        else:
            log_asset_action(cur, symbol, 'SKIPPED', reason, 'CRYPTO')
            skipped += 1

    return added, skipped, errors


def process_stock_candidates(cur, candidates: list[dict]) -> tuple[int, int, int]:
    added = skipped = errors = 0
    for cand in candidates:
        asset_type_low = cand['asset_type'].lower()
        if asset_type_low not in ('stock', 'etf'):
            continue
        symbol = cand['symbol'].upper()
        if not is_valid_symbol(symbol, asset_type_low):
            log_asset_action(cur, symbol, 'SKIPPED', 'Ongeldig symbool', asset_type_low.upper())
            skipped += 1
            continue

        market_data = get_stock_market_data(symbol)
        if not market_data:
            log_asset_action(cur, symbol, 'SKIPPED', 'Geen marktdata van Yahoo Finance', asset_type_low.upper())
            skipped += 1
            continue

        ok, reason = is_reliable_stock(market_data)
        if ok:
            if add_to_bot_symbols(cur, cand, reason):
                added += 1
                cur.connection.commit()
            else:
                skipped += 1
        else:
            log_asset_action(cur, symbol, 'SKIPPED', reason, asset_type_low.upper())
            skipped += 1

    return added, skipped, errors


def main():
    run_started = time.time()
    total_candidates = 0
    crypto_added = stock_added = crypto_skipped = stock_skipped = errors = 0

    log_bot("START", "run=begin")

    try:
        candidates = get_candidates_from_universe()
        total_candidates = len(candidates)
        log_bot("INFO", f"Aantal kandidaten: {total_candidates}")

        if not candidates:
            log_bot("END", "Geen kandidaten.")
            return

        db = get_db_connection()
        cur = db.cursor()

        crypto_candidates = [c for c in candidates if c['asset_type'].lower() == 'crypto']
        stock_candidates = [c for c in candidates if c['asset_type'].lower() in ('stock', 'etf')]

        if crypto_candidates:
            log_bot("INFO", f"Crypto kandidaten: {len(crypto_candidates)} -> bulk check")
            ca, cs, ce = process_crypto_candidates(cur, crypto_candidates)
            crypto_added = ca
            crypto_skipped = cs
            errors += ce

        if stock_candidates:
            log_bot("INFO", f"Stock/ETF kandidaten: {len(stock_candidates)}")
            sa, ss, se = process_stock_candidates(cur, stock_candidates)
            stock_added = sa
            stock_skipped = ss
            errors += se

        duration = time.time() - run_started
        log_bot("END", f"status=OK duration={duration:.2f}s total_candidates={total_candidates} "
                       f"crypto_added={crypto_added} crypto_skipped={crypto_skipped} "
                       f"stock_added={stock_added} stock_skipped={stock_skipped} errors={errors}")

    except Exception as e:
        duration = time.time() - run_started
        log_bot("ERROR", f"Fatal error: {e}", level="ERROR")
        log_bot("END", f"status=FAILED duration={duration:.2f}s", level="ERROR")
    finally:
        if 'cur' in locals() and cur:
            cur.close()
        if 'db' in locals() and db and db.is_connected():
            db.close()


if __name__ == "__main__":
    main()