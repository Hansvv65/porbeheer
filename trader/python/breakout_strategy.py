#!/usr/bin/env python3
from __future__ import annotations

import logging
import math
import os
import time
from datetime import datetime
from decimal import Decimal, ROUND_DOWN
from logging.handlers import RotatingFileHandler
from typing import Optional
from zoneinfo import ZoneInfo

import mysql.connector
import pandas as pd
import yfinance as yf

from db_config import DB_CONFIG


BOT_NAME = "BREAKOUT"
LOG_FILE = "/home/hans/trading_project/breakout_strategy.log"
LOG_LEVEL = logging.INFO
APP_TIMEZONE = "Europe/Amsterdam"

os.environ["TZ"] = APP_TIMEZONE
try:
    time.tzset()
except AttributeError:
    pass

# ---------- Strategieconstanten ----------
DEFAULT_STOP_LOSS_PCT = Decimal("-2.0")
DEFAULT_TAKE_PROFIT_PCT = Decimal("3.0")
DEFAULT_TRAILING_TRIGGER_PCT = Decimal("2.0")
DEFAULT_TRAILING_GAP_PCT = Decimal("1.0")
DEFAULT_MIN_TREND_SCORE = 4
DEFAULT_MAX_RSI_BUY = Decimal("72")

# Dipkoper-constanten
DIP_RSI_OVERSOLD = 35
DIP_BREAKOUT_CANDLES = 5           # kijk naar de hoogste high van de laatste 5 candles
DIP_ATR_TP_MULT = Decimal("1.5")   # take-profit in ATR
DIP_ATR_SL_MULT = Decimal("1.0")   # stop-loss in ATR

# ---------- Database DDL ----------
CREATE_SNAPSHOT_TABLE_SQL = """
CREATE TABLE IF NOT EXISTS asset_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    symbol VARCHAR(50) NOT NULL,
    snapshot_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    price DECIMAL(20,8) NOT NULL,
    open_price DECIMAL(20,8) DEFAULT NULL,
    high_price DECIMAL(20,8) DEFAULT NULL,
    low_price DECIMAL(20,8) DEFAULT NULL,
    volume DECIMAL(24,8) DEFAULT NULL,
    change_5m DECIMAL(12,4) DEFAULT NULL,
    change_1h DECIMAL(12,4) DEFAULT NULL,
    change_24h DECIMAL(12,4) DEFAULT NULL,
    sma_12 DECIMAL(20,8) DEFAULT NULL,
    sma_24 DECIMAL(20,8) DEFAULT NULL,
    ema_12 DECIMAL(20,8) DEFAULT NULL,
    ema_26 DECIMAL(20,8) DEFAULT NULL,
    rsi_14 DECIMAL(12,4) DEFAULT NULL,
    macd DECIMAL(20,8) DEFAULT NULL,
    macd_signal DECIMAL(20,8) DEFAULT NULL,
    adx_14 DECIMAL(12,4) DEFAULT NULL,
    atr_14 DECIMAL(20,8) DEFAULT NULL,
    volume_sma_20 DECIMAL(24,8) DEFAULT NULL,
    volatility_pct DECIMAL(12,4) DEFAULT NULL,
    trend_score INT DEFAULT 0,
    trend_state VARCHAR(20) DEFAULT 'SIDEWAYS',
    breakout TINYINT(1) DEFAULT 0,
    previous_high DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
    has_open_position TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_symbol_time (symbol, snapshot_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"""

CREATE_SIGNAL_LOG_TABLE_SQL = """
CREATE TABLE IF NOT EXISTS asset_signal_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    symbol VARCHAR(50) NOT NULL,
    signal_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    signal_type VARCHAR(30) NOT NULL,
    signal_reason VARCHAR(255) DEFAULT NULL,
    trend_state VARCHAR(20) DEFAULT NULL,
    trend_score INT DEFAULT 0,
    current_price DECIMAL(20,8) DEFAULT NULL,
    avg_price DECIMAL(20,8) DEFAULT NULL,
    breakout TINYINT(1) DEFAULT 0,
    strategy_run_id INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_symbol_signal_time (symbol, signal_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"""

CREATE_COOLDOWN_TABLE_SQL = """
CREATE TABLE IF NOT EXISTS cooldown_tracker (
    symbol VARCHAR(50) NOT NULL,
    last_loss_time DATETIME NOT NULL,
    PRIMARY KEY (symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"""

ALTER_POSITIONS_STRATEGY = """
ALTER TABLE positions
ADD COLUMN strategy VARCHAR(20) DEFAULT 'BREAKOUT' NOT NULL
"""


class AmsterdamFormatter(logging.Formatter):
    def formatTime(self, record, datefmt=None):
        dt = datetime.fromtimestamp(record.created, tz=ZoneInfo(APP_TIMEZONE))
        if datefmt:
            return dt.strftime(datefmt)
        return dt.strftime("%Y-%m-%d %H:%M:%S")


logger = logging.getLogger("breakout_strategy")
logger.setLevel(LOG_LEVEL)
logger.handlers.clear()

formatter = AmsterdamFormatter("%(asctime)s | %(levelname)s | %(message)s")

file_handler = RotatingFileHandler(LOG_FILE, maxBytes=2_000_000, backupCount=5)
file_handler.setFormatter(formatter)
logger.addHandler(file_handler)

stream_handler = logging.StreamHandler()
stream_handler.setFormatter(formatter)
logger.addHandler(stream_handler)

_TABLE_COLUMNS_CACHE: dict[str, set[str]] = {}


def is_trading_hours(symbol_row: dict) -> bool:
    now = datetime.now(ZoneInfo("Europe/Amsterdam"))
    weekday = now.weekday()
    hour = now.hour
    minute = now.minute

    if weekday >= 5:
        return False

    asset_type = str(symbol_row.get("asset_type") or "STOCK").upper()
    symbol = str(symbol_row.get("symbol") or "").upper()

    if asset_type == "CRYPTO":
        return True

    # Europese symbolen herkennen op suffix of bekende namen
    is_european = (
        symbol.endswith(".AS")
        or symbol.endswith(".DE")
        or symbol.endswith(".T")
        or symbol in {"AIR", "ALV", "SAP", "AZN", "SHEL", "TSCO", "SIE", "IFX"}
    )

    if is_european:
        # Euronext/Xetra: 09:00-17:30
        return (hour == 9 and minute >= 0) or (10 <= hour < 17) or (hour == 17 and minute <= 30)

    # Amerikaanse ETFs en aandelen: 15:30-22:00 Amsterdam
    return (hour == 15 and minute >= 30) or (16 <= hour < 22)

# ---------- Veilige Decimal conversies ----------
def safe_decimal(value, quantize_to: Optional[Decimal] = None) -> Decimal:
    if value is None:
        return Decimal(0)
    if isinstance(value, float) and (math.isnan(value) or math.isinf(value)):
        return Decimal(0)
    try:
        dec = Decimal(str(value))
    except Exception:
        return Decimal(0)
    if quantize_to is not None:
        return dec.quantize(quantize_to, rounding=ROUND_DOWN)
    return dec


def q8(value) -> Decimal:
    return safe_decimal(value, Decimal("0.00000001"))


def q4(value) -> Decimal:
    return safe_decimal(value, Decimal("0.0001"))


def q2(value) -> Decimal:
    return safe_decimal(value, Decimal("0.01"))


# ---------- Database helpers ----------
def get_db():
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
    sql = f"""
        INSERT INTO {table_name} ({", ".join(insert_cols)})
        VALUES ({placeholders})
    """
    cur.execute(sql, tuple(filtered[col] for col in insert_cols))
    return int(cur.lastrowid)


def update_with_existing_columns(cur, table_name: str, row_id: int, values: dict, id_column: str = "id") -> None:
    cols = get_table_columns(cur, table_name)
    filtered = {k: v for k, v in values.items() if k.lower() in cols}
    if not filtered:
        return

    assignments = ", ".join([f"{col} = %s" for col in filtered.keys()])
    sql = f"UPDATE {table_name} SET {assignments} WHERE {id_column} = %s"
    cur.execute(sql, tuple(filtered.values()) + (row_id,))


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


def fetch_one_dict(cur, sql: str, params=()):
    cur.execute(sql, params)
    row = cur.fetchone()
    if row is None:
        return None
    cols = [d[0] for d in cur.description]
    return dict(zip(cols, row))


def fetch_all_dict(cur, sql: str, params=()):
    cur.execute(sql, params)
    rows = cur.fetchall()
    cols = [d[0] for d in cur.description]
    return [dict(zip(cols, row)) for row in rows]


def ensure_analysis_tables() -> None:
    db = None
    cur = None
    try:
        db = get_db()
        cur = db.cursor()
        cur.execute(CREATE_SNAPSHOT_TABLE_SQL)
        cur.execute(CREATE_SIGNAL_LOG_TABLE_SQL)
        cur.execute(CREATE_COOLDOWN_TABLE_SQL)

        cur.execute("SHOW COLUMNS FROM positions LIKE 'strategy'")
        if not cur.fetchone():
            cur.execute(ALTER_POSITIONS_STRATEGY)
            logger.info("Kolom 'strategy' toegevoegd aan positions-tabel")
        db.commit()
    finally:
        if cur is not None:
            cur.close()
        if db is not None and db.is_connected():
            db.close()


def load_settings() -> Optional[dict]:
    db = None
    cur = None
    try:
        db = get_db()
        cur = db.cursor()
        return fetch_one_dict(cur, "SELECT * FROM bot_settings ORDER BY id ASC LIMIT 1")
    finally:
        if cur is not None:
            cur.close()
        if db is not None and db.is_connected():
            db.close()


def load_wallet() -> Optional[dict]:
    db = None
    cur = None
    try:
        db = get_db()
        cur = db.cursor()
        return fetch_one_dict(cur, "SELECT * FROM wallet WHERE is_active = 1 ORDER BY id ASC LIMIT 1")
    finally:
        if cur is not None:
            cur.close()
        if db is not None and db.is_connected():
            db.close()


def load_active_symbols() -> list[dict]:
    db = None
    cur = None
    try:
        db = get_db()
        cur = db.cursor()
        return fetch_all_dict(
            cur,
            """
            SELECT *
            FROM bot_symbols
            WHERE is_active = 1
            ORDER BY priority_order ASC, symbol ASC
            """
        )
    finally:
        if cur is not None:
            cur.close()
        if db is not None and db.is_connected():
            db.close()


def count_open_positions(cur) -> int:
    cur.execute("SELECT COUNT(*) FROM positions WHERE status = 'OPEN'")
    row = cur.fetchone()
    return int(row[0] if row else 0)


def get_open_position(cur, symbol: str) -> Optional[dict]:
    return fetch_one_dict(
        cur,
        """
        SELECT *
        FROM positions
        WHERE symbol = %s AND status = 'OPEN'
        ORDER BY id ASC
        LIMIT 1
        """,
        (symbol,),
    )


def normalize_yf_dataframe(df: pd.DataFrame) -> pd.DataFrame:
    if isinstance(df.columns, pd.MultiIndex):
        df.columns = [col[0] for col in df.columns]

    wanted = ["Open", "High", "Low", "Close", "Volume"]
    for col in wanted:
        if col not in df.columns:
            raise ValueError(f"Kolom {col} ontbreekt in ontvangen koersdata")

    df = df[wanted].copy()
    df = df.dropna(subset=["Close"])
    return df


def fetch_breakout_data(data_symbol: str, days: int) -> pd.DataFrame:
    log_bot("INFO", f"symbol={data_symbol} dagdata_ophalen days={days}", db_log=False)
    data = yf.download(
        data_symbol,
        period=f"{days + 5}d",
        interval="1d",
        auto_adjust=False,
        progress=False,
        group_by="column",
        threads=False,
    )
    if data.empty:
        raise ValueError(f"Geen dagdata ontvangen voor {data_symbol}")
    return normalize_yf_dataframe(data)


def fetch_intraday_data(data_symbol: str) -> pd.DataFrame:
    log_bot("INFO", f"symbol={data_symbol} intraday_ophalen period=5d interval=5m", db_log=False)
    data = yf.download(
        data_symbol,
        period="5d",
        interval="5m",
        auto_adjust=False,
        progress=False,
        group_by="column",
        threads=False,
    )
    if data.empty:
        raise ValueError(f"Geen intraday data ontvangen voor {data_symbol}")
    return normalize_yf_dataframe(data)


def compute_rsi(series: pd.Series, period: int = 14) -> pd.Series:
    delta = series.diff()
    gain = delta.clip(lower=0)
    loss = -delta.clip(upper=0)

    avg_gain = gain.rolling(window=period, min_periods=period).mean()
    avg_loss = loss.rolling(window=period, min_periods=period).mean()

    rs = avg_gain / avg_loss.replace(0, pd.NA)
    rsi = 100 - (100 / (1 + rs))
    return rsi.fillna(50)


# ---------- ADX / ATR / volume‑helpers ----------
def compute_adx(high: pd.Series, low: pd.Series, close: pd.Series, period: int = 14) -> pd.Series:
    tr1 = high - low
    tr2 = (high - close.shift()).abs()
    tr3 = (low - close.shift()).abs()
    true_range = pd.concat([tr1, tr2, tr3], axis=1).max(axis=1)
    atr = true_range.rolling(period).mean()

    up_move = high.diff()
    down_move = -low.diff()
    plus_dm = pd.Series(0.0, index=high.index)
    minus_dm = pd.Series(0.0, index=high.index)
    plus_dm[(up_move > down_move) & (up_move > 0)] = up_move
    minus_dm[(down_move > up_move) & (down_move > 0)] = down_move

    plus_di = 100 * (plus_dm.rolling(period).mean() / atr)
    minus_di = 100 * (minus_dm.rolling(period).mean() / atr)
    dx = (abs(plus_di - minus_di) / (plus_di + minus_di)) * 100
    adx = dx.rolling(period).mean()
    return adx


def compute_atr(high: pd.Series, low: pd.Series, close: pd.Series, period: int = 14) -> pd.Series:
    tr1 = high - low
    tr2 = (high - close.shift()).abs()
    tr3 = (low - close.shift()).abs()
    true_range = pd.concat([tr1, tr2, tr3], axis=1).max(axis=1)
    return true_range.rolling(period).mean()


def compute_indicators(intraday: pd.DataFrame) -> dict:
    df = intraday.copy()

    close = df["Close"]
    high = df["High"]
    low = df["Low"]
    volume = df["Volume"]

    sma12 = close.rolling(12).mean()
    sma24 = close.rolling(24).mean()
    ema12 = close.ewm(span=12, adjust=False).mean()
    ema26 = close.ewm(span=26, adjust=False).mean()
    macd = ema12 - ema26
    macd_signal = macd.ewm(span=9, adjust=False).mean()
    rsi14 = compute_rsi(close, 14)

    adx_series = compute_adx(high, low, close, 14)
    atr_series = compute_atr(high, low, close, 14)
    volume_sma_20 = volume.rolling(20).mean()

    current_close = q8(close.iloc[-1])
    current_open = q8(df["Open"].iloc[-1])
    current_high = q8(high.iloc[-1])
    current_low = q8(low.iloc[-1])
    current_volume = q8(volume.iloc[-1])

    change_5m = q4(((close.iloc[-1] / close.iloc[-2]) - 1) * 100) if len(close) >= 2 else q4(0)
    change_1h = q4(((close.iloc[-1] / close.iloc[-13]) - 1) * 100) if len(close) >= 13 else q4(0)
    change_24h = q4(((close.iloc[-1] / close.iloc[-289]) - 1) * 100) if len(close) >= 289 else q4(0)

    vol_pct = q4((((high.iloc[-1] - low.iloc[-1]) / close.iloc[-1]) * 100) if close.iloc[-1] else 0)

    trend_score = 0
    if current_close > q8(sma12.iloc[-1]):
        trend_score += 1
    if current_close > q8(sma24.iloc[-1]):
        trend_score += 1
    if q8(ema12.iloc[-1]) > q8(ema26.iloc[-1]):
        trend_score += 1
    if q8(macd.iloc[-1]) > q8(macd_signal.iloc[-1]):
        trend_score += 1
    if q4(rsi14.iloc[-1]) >= Decimal("55"):
        trend_score += 1
    if q4(change_1h) > Decimal("0"):
        trend_score += 1

    if trend_score >= 5:
        trend_state = "UP"
    elif trend_score <= 1:
        trend_state = "DOWN"
    else:
        trend_state = "SIDEWAYS"

    return {
        "current_close": current_close,
        "current_open": current_open,
        "current_high": current_high,
        "current_low": current_low,
        "current_volume": current_volume,
        "change_5m": change_5m,
        "change_1h": change_1h,
        "change_24h": change_24h,
        "sma12": q8(sma12.iloc[-1]),
        "sma24": q8(sma24.iloc[-1]),
        "ema12": q8(ema12.iloc[-1]),
        "ema26": q8(ema26.iloc[-1]),
        "rsi14": q4(rsi14.iloc[-1]),
        "macd": q8(macd.iloc[-1]),
        "macd_signal": q8(macd_signal.iloc[-1]),
        "volatility_pct": vol_pct,
        "trend_score": trend_score,
        "trend_state": trend_state,
        "adx_14": q4(adx_series.iloc[-1]) if not pd.isna(adx_series.iloc[-1]) else q4(0),
        "atr_14": q8(atr_series.iloc[-1]) if not pd.isna(atr_series.iloc[-1]) else q8(0),
        "volume_sma_20": q8(volume_sma_20.iloc[-1]) if not pd.isna(volume_sma_20.iloc[-1]) else q8(0),
    }


def create_strategy_run(cur, symbol: str, current_price: Decimal, previous_high: Decimal, breakout: int) -> int:
    return insert_with_existing_columns(
        cur,
        "strategy_runs",
        {
            "symbol": symbol,
            "current_price": current_price,
            "previous_high": previous_high,
            "breakout": breakout,
            "action_taken": "NONE",
            "notes": None,
            "created_at": datetime.now(ZoneInfo(APP_TIMEZONE)).strftime("%Y-%m-%d %H:%M:%S"),
        },
        required={"symbol", "current_price", "previous_high", "breakout", "action_taken"},
    )


def update_strategy_run(cur, strategy_run_id: int, action: str, notes: str) -> None:
    update_with_existing_columns(
        cur,
        "strategy_runs",
        strategy_run_id,
        {
            "action_taken": action,
            "notes": notes[:255] if notes else None,
        },
    )


def create_position(cur, symbol: str, quantity: Decimal, avg_price: Decimal, strategy: str = "BREAKOUT") -> int:
    return insert_with_existing_columns(
        cur,
        "positions",
        {
            "symbol": symbol,
            "quantity": quantity,
            "avg_price": avg_price,
            "highest_price": avg_price,
            "status": "OPEN",
            "strategy": strategy,
            "opened_at": datetime.now(ZoneInfo(APP_TIMEZONE)).strftime("%Y-%m-%d %H:%M:%S"),
            "created_at": datetime.now(ZoneInfo(APP_TIMEZONE)).strftime("%Y-%m-%d %H:%M:%S"),
        },
        required={"symbol", "quantity", "avg_price"},
    )


def create_trade(
    cur,
    symbol: str,
    trade_type: str,
    price: Decimal,
    amount: Decimal,
    profit_loss: Optional[Decimal],
    fees: Decimal,
    strategy_run_id: Optional[int],
    position_id: Optional[int],
    notes: str,
) -> int:
    return insert_with_existing_columns(
        cur,
        "trades",
        {
            "symbol": symbol,
            "asset": symbol,
            "type": trade_type,
            "price": price,
            "amount": amount,
            "profit_loss": profit_loss,
            "fees": fees,
            "strategy_run_id": strategy_run_id,
            "position_id": position_id,
            "notes": notes,
            "timestamp": datetime.now(ZoneInfo(APP_TIMEZONE)).strftime("%Y-%m-%d %H:%M:%S"),
            "created_at": datetime.now(ZoneInfo(APP_TIMEZONE)).strftime("%Y-%m-%d %H:%M:%S"),
        },
        required={"type", "price", "amount"},
    )


def insert_wallet_transaction(
    cur,
    wallet_id: int,
    transaction_type: str,
    amount: Decimal,
    balance_before: Decimal,
    balance_after: Decimal,
    reference_type: str,
    reference_id: int,
    description: str,
) -> None:
    insert_with_existing_columns(
        cur,
        "wallet_transactions",
        {
            "wallet_id": wallet_id,
            "transaction_type": transaction_type,
            "amount": amount,
            "balance_before": balance_before,
            "balance_after": balance_after,
            "reference_type": reference_type,
            "reference_id": reference_id,
            "description": description,
            "created_at": datetime.now(ZoneInfo(APP_TIMEZONE)).strftime("%Y-%m-%d %H:%M:%S"),
        },
        required={"wallet_id", "transaction_type", "amount"},
    )


def insert_snapshot(cur, symbol: str, analysis: dict, breakout: int, previous_high: Decimal, has_open_position: int) -> int:
    cur.execute(
        """
        INSERT INTO asset_snapshots (
            symbol, price, open_price, high_price, low_price, volume,
            change_5m, change_1h, change_24h,
            sma_12, sma_24, ema_12, ema_26,
            rsi_14, macd, macd_signal,
            adx_14, atr_14, volume_sma_20, volatility_pct,
            trend_score, trend_state,
            breakout, previous_high, has_open_position
        ) VALUES (
            %s, %s, %s, %s, %s, %s,
            %s, %s, %s,
            %s, %s, %s, %s,
            %s, %s, %s,
            %s, %s, %s, %s,
            %s, %s,
            %s, %s, %s
        )
        """,
        (
            symbol,
            analysis["current_close"], analysis["current_open"], analysis["current_high"], analysis["current_low"], analysis["current_volume"],
            analysis["change_5m"], analysis["change_1h"], analysis["change_24h"],
            analysis["sma12"], analysis["sma24"], analysis["ema12"], analysis["ema26"],
            analysis["rsi14"], analysis["macd"], analysis["macd_signal"],
            analysis.get("adx_14", q4(0)), analysis.get("atr_14", q8(0)), analysis.get("volume_sma_20", q8(0)), analysis["volatility_pct"],
            analysis["trend_score"], analysis["trend_state"],
            breakout, previous_high, has_open_position,
        ),
    )
    return int(cur.lastrowid)


def insert_signal_log(
    cur,
    symbol: str,
    signal_type: str,
    signal_reason: str,
    trend_state: str,
    trend_score: int,
    current_price: Decimal,
    avg_price: Optional[Decimal],
    breakout: int,
    strategy_run_id: Optional[int],
) -> int:
    cur.execute(
        """
        INSERT INTO asset_signal_log (
            symbol, signal_type, signal_reason, trend_state, trend_score,
            current_price, avg_price, breakout, strategy_run_id
        ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
        """,
        (
            symbol, signal_type, signal_reason[:255], trend_state, trend_score,
            current_price, avg_price, breakout, strategy_run_id,
        ),
    )
    return int(cur.lastrowid)


# ---------- Transactiekosten ----------
def calculate_transaction_cost(cur, asset_type: str, exchange: Optional[str], order_value: Decimal) -> Decimal:
    if order_value <= 0:
        return Decimal("0")

    currency_fee = Decimal("0")
    if exchange and exchange.upper() not in ("EURONEXT", "AMS", "DEFAULT"):
        currency_fee = order_value * Decimal("0.0025") + Decimal("10")

    fee_row = fetch_one_dict(cur, """
        SELECT fee_type, fee_amount, min_fee, max_fee
        FROM trading_fees
        WHERE asset_type = %s
          AND (exchange_name = %s OR exchange_name = 'DEFAULT')
          AND is_active = 1
        ORDER BY CASE WHEN exchange_name = %s THEN 0 ELSE 1 END
        LIMIT 1
    """, (asset_type.upper(), exchange or "DEFAULT", exchange or "DEFAULT"))

    if not fee_row:
        log_bot("WARNING", f"Geen fee configuratie gevonden voor {asset_type}/{exchange}, kosten = 0", db_log=False)
        return currency_fee

    if fee_row["fee_type"] == "FIXED":
        cost = Decimal(str(fee_row["fee_amount"]))
    else:
        cost = order_value * Decimal(str(fee_row["fee_amount"])) / Decimal("100")

    if fee_row.get("min_fee") is not None:
        cost = max(cost, Decimal(str(fee_row["min_fee"])))
    if fee_row.get("max_fee") is not None:
        cost = min(cost, Decimal(str(fee_row["max_fee"])))

    total_cost = cost + currency_fee
    return total_cost.quantize(Decimal("0.00000001"), rounding=ROUND_DOWN)


# ---------- execute_sell / execute_buy ----------
def execute_sell(
    cur,
    symbol: str,
    position: dict,
    current_price: Decimal,
    strategy_run_id: int,
    reason: str,
) -> bool:
    wallet = fetch_one_dict(cur, "SELECT id, balance FROM wallet WHERE is_active = 1 ORDER BY id LIMIT 1 FOR UPDATE")
    if not wallet:
        update_strategy_run(cur, strategy_run_id, "NONE", "Geen actieve wallet")
        return False

    wallet_id = int(wallet["id"])
    quantity = q8(position["quantity"])
    avg_price = q8(position["avg_price"])

    asset_info = fetch_one_dict(cur, "SELECT asset_type FROM bot_symbols WHERE symbol = %s", (symbol,))
    asset_type = asset_info["asset_type"] if asset_info else "STOCK"

    proceeds = (quantity * current_price).quantize(Decimal("0.00000001"), rounding=ROUND_DOWN)
    fees = calculate_transaction_cost(cur, asset_type, None, proceeds)
    proceeds_after_fees = (proceeds - fees).quantize(Decimal("0.00000001"), rounding=ROUND_DOWN)

    profit_loss = (proceeds_after_fees - (quantity * avg_price)).quantize(Decimal("0.00000001"), rounding=ROUND_DOWN)

    balance_before = q8(wallet["balance"])
    balance_after = (balance_before + proceeds_after_fees).quantize(Decimal("0.00000001"), rounding=ROUND_DOWN)
    cur.execute("UPDATE wallet SET balance = %s WHERE id = %s", (balance_after, wallet_id))

    cur.execute(
        "UPDATE positions SET status = 'CLOSED', closed_at = NOW() WHERE id = %s",
        (position["id"],)
    )

    trade_id = create_trade(
        cur=cur,
        symbol=symbol,
        trade_type="SELL",
        price=current_price,
        amount=quantity,
        profit_loss=profit_loss,
        fees=fees,
        strategy_run_id=strategy_run_id,
        position_id=position["id"],
        notes=f"Paper SELL {symbol} ({position.get('strategy','BREAKOUT')}) | fees={fees}",
    )

    insert_wallet_transaction(
        cur=cur,
        wallet_id=wallet_id,
        transaction_type="SELL",
        amount=proceeds_after_fees,
        balance_before=balance_before,
        balance_after=balance_after,
        reference_type="TRADE",
        reference_id=trade_id,
        description=f"Paper SELL {symbol} qty={quantity} price={current_price} (fees={fees})",
    )

    update_strategy_run(cur, strategy_run_id, "SELL", reason)
    log_bot("INFO", f"symbol={symbol} action=SELL qty={quantity} price={current_price} profit={profit_loss} fees={fees}", db_log=True)
    return True


def execute_buy(
    cur,
    symbol: str,
    asset_type: str,
    order_symbol: str,
    current_close: Decimal,
    amount_eur: Decimal,    # wordt nu genegeerd
    strategy_run_id: int,
    analysis: dict,
    strategy: str = "BREAKOUT",
) -> bool:
    wallet = fetch_one_dict(cur, "SELECT id, balance FROM wallet WHERE is_active = 1 ORDER BY id LIMIT 1 FOR UPDATE")
    if not wallet:
        update_strategy_run(cur, strategy_run_id, "NONE", "Geen actieve wallet")
        return False

    wallet_id = int(wallet["id"])
    balance_before = q8(wallet["balance"])

   # Risicopercentage (1% van portefeuille)
    risk_amount = balance_before * Decimal("0.01")
    atr = analysis.get("atr_14", q8(0))
    if atr <= 0:
        stop_distance = current_close * (abs(DEFAULT_STOP_LOSS_PCT) / Decimal("100"))
    else:
        if strategy == "DIP":
            stop_distance = DIP_ATR_SL_MULT * atr
        else:
            stop_distance = Decimal("1.5") * atr

    if stop_distance <= 0:
        log_bot("WARNING", f"BUY_FAILED symbol={symbol} reden=STOP_DISTANCE_NUL atr={atr}")
        update_strategy_run(cur, strategy_run_id, "NONE", "Stopafstand 0")
        return False

    # Quantity op basis van risico, maar nooit meer dan amount_eur waard
    qty_by_risk = (risk_amount / stop_distance).quantize(Decimal("0.00000001"), rounding=ROUND_DOWN)
    qty_by_budget = (amount_eur / current_close).quantize(Decimal("0.00000001"), rounding=ROUND_DOWN)
    quantity = min(qty_by_risk, qty_by_budget)
    

    if quantity <= Decimal("0"):
        log_bot("WARNING", f"BUY_FAILED symbol={symbol} reden=QUANTITY_NUL risk={risk_amount} stop_dist={stop_distance}")
        update_strategy_run(cur, strategy_run_id, "NONE", "Quantity is 0")
        return False

    total_cost = (quantity * current_close).quantize(Decimal("0.00000001"), rounding=ROUND_DOWN)
    fees = calculate_transaction_cost(cur, asset_type, None, total_cost)
    total_cost_with_fees = (total_cost + fees).quantize(Decimal("0.00000001"), rounding=ROUND_DOWN)

    if balance_before < total_cost_with_fees:
        log_bot("WARNING", f"BUY_FAILED symbol={symbol} reden=SALDO_TE_LAAG balance={balance_before} cost={total_cost_with_fees} qty={quantity} price={current_close}")
        update_strategy_run(cur, strategy_run_id, "NONE", f"Onvoldoende saldo (incl. {fees} kosten)")
        return False

    balance_after = (balance_before - total_cost_with_fees).quantize(Decimal("0.00000001"), rounding=ROUND_DOWN)
    cur.execute("UPDATE wallet SET balance = %s WHERE id = %s", (balance_after, wallet_id))

    position_id = create_position(cur=cur, symbol=symbol, quantity=quantity, avg_price=current_close, strategy=strategy)

    trade_id = create_trade(
        cur=cur,
        symbol=symbol,
        trade_type="BUY",
        price=current_close,
        amount=quantity,
        profit_loss=None,
        fees=fees,
        strategy_run_id=strategy_run_id,
        position_id=position_id,
        notes=f"Paper BUY {symbol} ({strategy}) (fees={fees})",
    )

    insert_wallet_transaction(
        cur=cur,
        wallet_id=wallet_id,
        transaction_type="BUY",
        amount=total_cost_with_fees,
        balance_before=balance_before,
        balance_after=balance_after,
        reference_type="TRADE",
        reference_id=trade_id,
        description=f"Paper BUY {symbol} qty={quantity} price={current_close} ({strategy}) (fees={fees})",
    )

    update_strategy_run(cur, strategy_run_id, "BUY", f"{strategy} BUY uitgevoerd ({asset_type})")
    log_bot("INFO", f"symbol={symbol} action=BUY strategy={strategy} qty={quantity} price={current_close} fees={fees}", db_log=True)
    return True

# ---------- Verkooplogica (inclusief dip) ----------
def should_sell(open_position: dict, analysis: dict) -> tuple[bool, str]:
    avg_price = q8(open_position["avg_price"])
    current_close = analysis["current_close"]
    current_high = analysis["current_high"]
    trend_state = analysis["trend_state"]
    trend_score = analysis["trend_score"]
    atr = analysis.get("atr_14", q8(0))
    strategy = open_position.get("strategy", "BREAKOUT").upper()

    if avg_price <= 0:
        return False, "ONGELDIGE_AVG_PRICE"

    # Dip-strategie: alleen ATR-gebaseerde stop/target, geen trend
    if strategy == "DIP":
        if atr > 0:
            stop_loss_price = avg_price - DIP_ATR_SL_MULT * atr
            take_profit_price = avg_price + DIP_ATR_TP_MULT * atr
        else:
            stop_loss_price = avg_price * (Decimal("1") + DEFAULT_STOP_LOSS_PCT / Decimal("100"))
            take_profit_price = avg_price * (Decimal("1") + DEFAULT_TAKE_PROFIT_PCT / Decimal("100"))

        if current_close <= stop_loss_price:
            return True, f"DIP_STOP_LOSS {stop_loss_price} current={current_close}"
        if current_close >= take_profit_price:
            return True, f"DIP_TAKE_PROFIT {take_profit_price} current={current_close}"
        return False, "DIP_HOLD"

    # Standaard breakout-logica
    if atr > 0:
        stop_loss_price = avg_price - (Decimal("1.5") * atr)
        take_profit_price = avg_price + (Decimal("3") * atr)
        trailing_trigger_price = avg_price + (Decimal("2") * atr)
        trailing_stop_offset = Decimal("1") * atr
    else:
        stop_loss_price = avg_price * (Decimal("1") + (DEFAULT_STOP_LOSS_PCT / Decimal("100")))
        take_profit_price = avg_price * (Decimal("1") + (DEFAULT_TAKE_PROFIT_PCT / Decimal("100")))
        trailing_trigger_price = avg_price * (Decimal("1") + (DEFAULT_TRAILING_TRIGGER_PCT / Decimal("100")))
        trailing_stop_offset = avg_price * (DEFAULT_TRAILING_GAP_PCT / Decimal("100"))

    if current_close <= stop_loss_price:
        return True, f"STOP_LOSS dynamic_level={stop_loss_price} current={current_close}"

    if current_close >= take_profit_price and trend_score <= 1:
        return True, f"TAKE_PROFIT_WEAK_TREND dynamic_level={take_profit_price} current={current_close}"

    highest_price = q8(open_position.get("highest_price", current_high))
    if current_close >= trailing_trigger_price:
        trailing_stop = highest_price - trailing_stop_offset
        if current_close <= trailing_stop:
            return True, f"TRAILING_STOP highest={highest_price} stop={trailing_stop} current={current_close}"

    if trend_state == "DOWN" and trend_score <= 1:
        return True, f"TREND_REVERSAL score={trend_score}"

    return False, "HOLD"


# ---------- Koopsignalen (breakout + dip) ----------
def should_buy(breakout: int, analysis: dict, cur, symbol: str, day_data: pd.DataFrame, window: int, asset_type: str = "STOCK") -> tuple[bool, str]:
    """Controleert koopsignalen, met optionele volume‑check op basis van asset type."""
    # Volume‑bevestiging alleen voor STOCK en ETF, en met ratio 1.0 (geen piek)
    if asset_type.upper() in ("STOCK", "ETF"):
        volume_series = day_data["Volume"].dropna()
        if len(volume_series) < 20:
            return False, "ONVOLDOENDE_VOLUME_DATA"
        avg_vol = volume_series.rolling(20).mean().iloc[-1]
        if avg_vol <= 0:
            return False, "VOLUME_DATA_ONGELDIG"
        if volume_series.iloc[-1] < 0.5 * avg_vol:
            return False, "VOLUME_TE_LAAG"

    # ADX‑sterkte
    adx = analysis.get("adx_14", q4(0))
    if adx < 15:
        return False, f"ADX_TE_LAAG adx={adx:.2f}"

    if breakout != 1:
        return False, "NO_BREAKOUT"

    trend_state = analysis["trend_state"]
    trend_score = analysis["trend_score"]
    rsi14 = analysis.get("rsi14", q4(50))
    if trend_state != "UP":
        return False, f"TREND_NOT_UP score={trend_score}"
    if trend_score < DEFAULT_MIN_TREND_SCORE:
        return False, f"TREND_TOO_WEAK score={trend_score} min={DEFAULT_MIN_TREND_SCORE}"
    if rsi14 > DEFAULT_MAX_RSI_BUY:
        return False, f"RSI_TOO_HIGH rsi={rsi14} max={DEFAULT_MAX_RSI_BUY}"

    # Cooldown
    last_loss = fetch_one_dict(cur, """
    SELECT MAX(timestamp) as last_loss_time
    FROM trades
    WHERE asset = %s AND profit_loss < 0
    """, (symbol,))
    if last_loss and last_loss["last_loss_time"]:
        time_diff = datetime.now(ZoneInfo(APP_TIMEZONE)) - last_loss["last_loss_time"]
        if time_diff.total_seconds() < 24 * 3600:
            return False, "COOLDOWN_AFTER_LOSS"

    return True, f"BUY_SIGNAL breakout=1 score={trend_score} rsi={rsi14} adx={adx}"


def should_buy_dip(analysis: dict, intraday: pd.DataFrame, cur, symbol: str) -> tuple[bool, str]:
    """
    Detecteert V‑vormig herstel: minstens één RSI < 35 in de laatste 5 candles,
    huidige RSI ≥ 35, en de prijs breekt boven de hoogste high van die 5 candles.
    ADX moet < 25 zijn (zijwaartse/consoliderende markt).
    """
    rsi14 = analysis.get("rsi14", q4(50))
    atr = analysis.get("atr_14", q8(0))
    current_close = analysis["current_close"]

    # Vereist: recente RSI heeft oversold gezien, en is nu hersteld
    rsi_values = compute_rsi(intraday["Close"], 14)
    if len(rsi_values) < DIP_BREAKOUT_CANDLES + 1:
        return False, "ONVOLDOENDE_INTRADAY_RSI"

    recent_rsi = rsi_values.iloc[-DIP_BREAKOUT_CANDLES:]   # laatste 5 candles
    current_rsi = rsi_values.iloc[-1]                      # huidige candle

    if recent_rsi.min() >= DIP_RSI_OVERSOLD:               # nooit oversold geweest
        return False, f"DIP_NO_OVERSOLD min_rsi={recent_rsi.min():.2f}"
    if current_rsi < DIP_RSI_OVERSOLD:                     # nog steeds oversold, geen herstel
        return False, f"DIP_STILL_OVERSOLD rsi={current_rsi:.2f}"

    # Uitbraak boven weerstand (hoogste high van de laatste 5 candles, exclusief huidige)
    if len(intraday) < DIP_BREAKOUT_CANDLES + 1:
        return False, "ONVOLDOENDE_INTRADAY_DATA"

    previous_highs = intraday["High"].iloc[-(DIP_BREAKOUT_CANDLES+1):-1]
    resistance = previous_highs.max()
    if current_close <= resistance:
        return False, f"DIP_NO_BREAKOUT resistance={resistance} current={current_close}"

    # ADX laag (consolidatie)
    adx = analysis.get("adx_14", q4(0))
    if adx >= 25:
        return False, f"DIP_ADX_TOO_HIGH adx={adx:.2f}"

    # Cooldown
    last_loss = fetch_one_dict(cur, """
        SELECT MAX(timestamp) as last_loss_time
        FROM trades
        WHERE asset = %s AND profit_loss < 0
    """, (symbol,))
    if last_loss and last_loss["last_loss_time"]:
        time_diff = datetime.now(ZoneInfo(APP_TIMEZONE)) - last_loss["last_loss_time"]
        if time_diff.total_seconds() < 24 * 3600:
            return False, "COOLDOWN_AFTER_LOSS"

    return True, f"DIP_BUY resistance={resistance} close={current_close} rsi={current_rsi} atr={atr}"


# ---------- Hoofdverwerking per symbool ----------
def process_symbol(symbol_row: dict, settings: dict) -> dict:
    db = None
    cur = None
    symbol = str(symbol_row.get("symbol") or "UNKNOWN").upper().strip()

    result = {
        "symbol": symbol,
        "status": "ERROR",
        "outcome": "ERROR",
        "message": "",
    }

    if not is_trading_hours(symbol_row):
            result["status"] = "OK"        # ← was vergeten
            result["outcome"] = "SKIP"
            result["message"] = "BUITEN_HANDELSTIJDEN"
            return result

    try:
        asset_type = str(symbol_row.get("asset_type") or "STOCK").upper().strip()
        data_symbol = str(symbol_row.get("data_symbol") or symbol).strip()
        order_symbol = str(symbol_row.get("order_symbol") or symbol).strip()

        window = int(settings["breakout_window"])
        amount_eur = q2(settings["amount_per_trade_eur"])
        trade_enabled = int(settings["trade_enabled"])
        max_open_positions = int(settings["max_open_positions"])
        dip_enabled = settings.get("dip_buying_enabled", 0)

        log_bot(
            "INFO",
            f"symbol={symbol} status=START data_symbol={data_symbol} order_symbol={order_symbol}"
        )

        day_data = fetch_breakout_data(data_symbol, days=max(window + 1, 30))
        intraday = fetch_intraday_data(data_symbol)
        analysis = compute_indicators(intraday)

        closes_daily = day_data["Close"].dropna()
        highs_daily = day_data["High"].dropna()
        volumes_daily = day_data["Volume"].dropna()

        if len(closes_daily) < 2 or len(highs_daily) < window + 1:
            raise ValueError(f"Onvoldoende dagdata voor {data_symbol}")

        current_close = analysis["current_close"]

        if len(highs_daily) > window:
            previous_high_raw = highs_daily.iloc[-(window + 1):-1].max()
        else:
            previous_high_raw = highs_daily.iloc[:-1].max()
        previous_high = q8(previous_high_raw)

        last_daily_close = q8(closes_daily.iloc[-1])
        breakout_confirmed = last_daily_close > previous_high
        breakout = 1 if breakout_confirmed else 0

        db = get_db()
        cur = db.cursor()

        open_position = get_open_position(cur, symbol)
        has_open_position = 1 if open_position else 0

        if open_position:
            current_high = analysis["current_high"]
            old_highest = open_position.get("highest_price")
            if old_highest is None:
                old_highest = current_high
            new_highest = max(q8(old_highest), current_high)
            if new_highest != q8(old_highest):
                cur.execute(
                    "UPDATE positions SET highest_price = %s WHERE id = %s",
                    (new_highest, open_position["id"])
                )
                open_position["highest_price"] = new_highest

        strategy_run_id = create_strategy_run(cur, symbol, current_close, previous_high, breakout)

        insert_snapshot(cur, symbol, analysis, breakout, previous_high, has_open_position)

        if open_position:
            sell_ok, sell_reason = should_sell(open_position, analysis)
            avg_price = q8(open_position["avg_price"])

            if sell_ok:
                execute_sell(cur, symbol, open_position, current_close, strategy_run_id, sell_reason)
                insert_signal_log(
                    cur, symbol, "SELL_SIGNAL", sell_reason,
                    analysis["trend_state"], analysis["trend_score"],
                    current_close, avg_price, breakout, strategy_run_id
                )
                db.commit()
                result["status"] = "OK"
                result["outcome"] = "SELL_SIGNAL"
                result["message"] = sell_reason
                log_bot(
                    "INFO",
                    f"symbol={symbol} status=OK outcome=SELL_SIGNAL breakout={breakout} reason={sell_reason}"
                )
                return result

            update_strategy_run(cur, strategy_run_id, "NONE", sell_reason)
            insert_signal_log(
                cur, symbol, "HOLD", sell_reason,
                analysis["trend_state"], analysis["trend_score"],
                current_close, avg_price, breakout, strategy_run_id
            )
            db.commit()
            result["status"] = "OK"
            result["outcome"] = "HOLD"
            result["message"] = sell_reason
            log_bot(
                "INFO",
                f"symbol={symbol} status=OK outcome=HOLD breakout={breakout} reason={sell_reason}"
            )
            return result

        if trade_enabled != 1:
            reason = "TRADE_DISABLED"
            update_strategy_run(cur, strategy_run_id, "NONE", reason)
            insert_signal_log(
                cur, symbol, "SKIP", reason,
                analysis["trend_state"], analysis["trend_score"],
                current_close, None, breakout, strategy_run_id
            )
            db.commit()
            result["status"] = "OK"
            result["outcome"] = "SKIP"
            result["message"] = reason
            log_bot(
                "INFO",
                f"symbol={symbol} status=OK outcome=SKIP breakout={breakout} reason={reason}"
            )
            return result

        # Normale breakout
        buy_ok, buy_reason = should_buy(breakout, analysis, cur, symbol, day_data, window, asset_type)

        if not buy_ok:
            # Dip-strategie als breakout faalt
            if dip_enabled == 1:
                dip_ok, dip_reason = should_buy_dip(analysis, intraday, cur, symbol)
                if dip_ok:
                    buy_ok = True
                    buy_reason = dip_reason
                    strategy_used = "DIP"
                else:
                    log_bot("DEBUG", f"symbol={symbol} dip_failed reason={dip_reason}", level="DEBUG", db_log=False)
                    strategy_used = "BREAKOUT"
            else:
                strategy_used = "BREAKOUT"
        else:
            strategy_used = "BREAKOUT"

        if not buy_ok:
            update_strategy_run(cur, strategy_run_id, "NONE", buy_reason)
            insert_signal_log(
                cur, symbol, "SKIP", buy_reason,
                analysis["trend_state"], analysis["trend_score"],
                current_close, None, breakout, strategy_run_id
            )
            db.commit()
            result["status"] = "OK"
            result["outcome"] = "SKIP"
            result["message"] = buy_reason
            log_bot(
                "INFO",
                f"symbol={symbol} status=OK outcome=SKIP breakout={breakout} reason={buy_reason}"
            )
            return result

        open_positions = count_open_positions(cur)
        if open_positions >= max_open_positions:
            reason = "MAX_OPEN_POSITIONS_BEREIKT"
            update_strategy_run(cur, strategy_run_id, "NONE", reason)
            insert_signal_log(
                cur, symbol, "SKIP", reason,
                analysis["trend_state"], analysis["trend_score"],
                current_close, None, breakout, strategy_run_id
            )
            db.commit()
            result["status"] = "OK"
            result["outcome"] = "SKIP"
            result["message"] = reason
            log_bot(
                "INFO",
                f"symbol={symbol} status=OK outcome=SKIP breakout={breakout} reason={reason}"
            )
            return result

        buy_done = execute_buy(
            cur=cur,
            symbol=symbol,
            asset_type=asset_type,
            order_symbol=order_symbol,
            current_close=current_close,
            amount_eur=amount_eur,
            strategy_run_id=strategy_run_id,
            analysis=analysis,
            strategy=strategy_used,
        )

        if buy_done:
            insert_signal_log(
                cur, symbol, "BUY", buy_reason,
                analysis["trend_state"], analysis["trend_score"],
                current_close, None, breakout, strategy_run_id
            )
            result["status"] = "OK"
            result["outcome"] = "BUY"
            result["message"] = buy_reason
            log_bot(
                "INFO",
                f"symbol={symbol} status=OK outcome=BUY strategy={strategy_used} breakout={breakout} reason={buy_reason}"
            )
        else:
            insert_signal_log(
                cur, symbol, "SKIP", "BUY_FAILED",
                analysis["trend_state"], analysis["trend_score"],
                current_close, None, breakout, strategy_run_id
            )
            result["status"] = "OK"
            result["outcome"] = "SKIP"
            result["message"] = "BUY_FAILED"
            log_bot(
                "WARNING",
                f"symbol={symbol} status=OK outcome=SKIP breakout={breakout} reason=BUY_FAILED",
                level="WARNING"
            )

        db.commit()
        return result

    except Exception as exc:
        if db is not None:
            try:
                db.rollback()
            except Exception:
                pass
        result["status"] = "ERROR"
        result["outcome"] = "ERROR"
        result["message"] = str(exc)
        log_bot("ERROR", f"symbol={symbol} status=FAILED error={exc}", level="ERROR")
        return result

    finally:
        if cur is not None:
            cur.close()
        if db is not None and db.is_connected():
            db.close()


def main():
    run_started = time.time()

    total_symbols = 0
    ok_count = 0
    error_count = 0
    buy_count = 0
    hold_count = 0
    skip_count = 0
    sell_signal_count = 0

    log_bot("START", "run=begin")

    try:
        ensure_analysis_tables()

        settings = load_settings()
        if not settings:
            log_bot("ERROR", "status=FAILED reason=GEEN_BOT_SETTINGS", level="ERROR")
            duration = round(time.time() - run_started, 2)
            log_bot("END", f"status=FAILED duration={duration}s", level="ERROR")
            return

        if int(settings["bot_enabled"]) != 1:
            duration = round(time.time() - run_started, 2)
            log_bot("END", f"status=STOPPED reason=BOT_DISABLED duration={duration}s")
            return

        wallet = load_wallet()
        if not wallet:
            log_bot("ERROR", "status=FAILED reason=GEEN_ACTIEVE_WALLET", level="ERROR")
            duration = round(time.time() - run_started, 2)
            log_bot("END", f"status=FAILED duration={duration}s", level="ERROR")
            return

        symbols = load_active_symbols()
        if not symbols:
            duration = round(time.time() - run_started, 2)
            log_bot("END", f"status=STOPPED reason=GEEN_ACTIEVE_SYMBOLS duration={duration}s")
            return

        total_symbols = len(symbols)

        log_bot(
            "INFO",
            f"symbols={len(symbols)} breakout_window={settings['breakout_window']} "
            f"trade_enabled={settings['trade_enabled']} paper_mode={settings['paper_mode']} "
            f"max_open_positions={settings['max_open_positions']} dip_enabled={settings.get('dip_buying_enabled',0)}"
        )

        for row in symbols:
            result = process_symbol(row, settings)

            if result["status"] == "OK":
                ok_count += 1
            else:
                error_count += 1

            outcome = result["outcome"]
            if outcome == "BUY":
                buy_count += 1
            elif outcome == "HOLD":
                hold_count += 1
            elif outcome == "SKIP":
                skip_count += 1
            elif outcome == "SELL_SIGNAL":
                sell_signal_count += 1

        log_bot("INFO", "snapshot_cleanup=OVERGESLAGEN reden=KOERSVERLOOP_BEWAREN")

        duration = round(time.time() - run_started, 2)
        log_bot(
            "END",
            f"status=OK duration={duration}s symbols={total_symbols} ok={ok_count} errors={error_count} "
            f"buy={buy_count} hold={hold_count} skip={skip_count} sell_signal={sell_signal_count}"
        )

    except Exception as exc:
        duration = round(time.time() - run_started, 2)
        log_bot("ERROR", f"status=FAILED error={exc}", level="ERROR")
        log_bot("END", f"status=FAILED duration={duration}s", level="ERROR")


if __name__ == "__main__":
    main()