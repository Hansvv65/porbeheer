#!/usr/bin/env python3
from __future__ import annotations

import logging
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

DEFAULT_STOP_LOSS_PCT = Decimal("-2.0")
DEFAULT_TAKE_PROFIT_PCT = Decimal("3.0")
DEFAULT_TRAILING_TRIGGER_PCT = Decimal("2.0")
DEFAULT_TRAILING_GAP_PCT = Decimal("1.0")
DEFAULT_MIN_TREND_SCORE = 4
DEFAULT_MAX_RSI_BUY = Decimal("72")

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


def to_float(value) -> float:
    if value is None:
        return 0.0
    if hasattr(value, "item"):
        return float(value.item())
    return float(value)


def q8(value) -> Decimal:
    return Decimal(str(value)).quantize(Decimal("0.00000001"), rounding=ROUND_DOWN)


def q4(value) -> Decimal:
    return Decimal(str(value)).quantize(Decimal("0.0001"), rounding=ROUND_DOWN)


def q2(value) -> Decimal:
    return Decimal(str(value)).quantize(Decimal("0.01"), rounding=ROUND_DOWN)


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


def create_position(cur, symbol: str, quantity: Decimal, avg_price: Decimal) -> int:
    return insert_with_existing_columns(
        cur,
        "positions",
        {
            "symbol": symbol,
            "quantity": quantity,
            "avg_price": avg_price,
            "status": "OPEN",
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


def insert_snapshot(
    cur,
    symbol: str,
    analysis: dict,
    breakout: int,
    previous_high: Decimal,
    has_open_position: int,
) -> int:
    cur.execute(
        """
        INSERT INTO asset_snapshots (
            symbol, price, open_price, high_price, low_price, volume,
            change_5m, change_1h, change_24h,
            sma_12, sma_24, ema_12, ema_26,
            rsi_14, macd, macd_signal, volatility_pct,
            trend_score, trend_state,
            breakout, previous_high, has_open_position
        ) VALUES (
            %s, %s, %s, %s, %s, %s,
            %s, %s, %s,
            %s, %s, %s, %s,
            %s, %s, %s, %s,
            %s, %s,
            %s, %s, %s
        )
        """,
        (
            symbol,
            analysis["current_close"],
            analysis["current_open"],
            analysis["current_high"],
            analysis["current_low"],
            analysis["current_volume"],
            analysis["change_5m"],
            analysis["change_1h"],
            analysis["change_24h"],
            analysis["sma12"],
            analysis["sma24"],
            analysis["ema12"],
            analysis["ema26"],
            analysis["rsi14"],
            analysis["macd"],
            analysis["macd_signal"],
            analysis["volatility_pct"],
            analysis["trend_score"],
            analysis["trend_state"],
            breakout,
            previous_high,
            has_open_position,
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


def execute_sell_not_implemented(cur, symbol: str, reason: str, strategy_run_id: int) -> None:
    update_strategy_run(cur, strategy_run_id, "NONE", f"SELL nog niet geïmplementeerd: {reason}")
    log_bot("INFO", f"symbol={symbol} action=SELL_SIGNAL status=NOT_IMPLEMENTED reason={reason}", db_log=True)


def execute_buy(
    cur,
    symbol: str,
    asset_type: str,
    order_symbol: str,
    current_close: Decimal,
    amount_eur: Decimal,
    strategy_run_id: int,
) -> bool:
    wallet = fetch_one_dict(
        cur,
        "SELECT id, balance FROM wallet WHERE is_active = 1 ORDER BY id ASC LIMIT 1 FOR UPDATE",
    )
    if not wallet:
        update_strategy_run(cur, strategy_run_id, "NONE", "Geen actieve wallet gevonden")
        return False

    wallet_id = int(wallet["id"])
    balance_before = q8(wallet["balance"])

    quantity = (amount_eur / current_close).quantize(Decimal("0.00000001"), rounding=ROUND_DOWN)
    if quantity <= Decimal("0"):
        update_strategy_run(cur, strategy_run_id, "NONE", "Berekende quantity is 0")
        return False

    total_cost = (quantity * current_close).quantize(Decimal("0.00000001"), rounding=ROUND_DOWN)

    if balance_before < total_cost:
        update_strategy_run(cur, strategy_run_id, "NONE", "Onvoldoende saldo in wallet")
        return False

    balance_after = (balance_before - total_cost).quantize(Decimal("0.00000001"), rounding=ROUND_DOWN)
    cur.execute("UPDATE wallet SET balance = %s WHERE id = %s", (balance_after, wallet_id))

    position_id = create_position(cur=cur, symbol=symbol, quantity=quantity, avg_price=current_close)

    trade_id = create_trade(
        cur=cur,
        symbol=symbol,
        trade_type="BUY",
        price=current_close,
        amount=quantity,
        profit_loss=None,
        strategy_run_id=strategy_run_id,
        position_id=position_id,
        notes=f"Paper breakout buy ({asset_type}) order_symbol={order_symbol}",
    )

    insert_wallet_transaction(
        cur=cur,
        wallet_id=wallet_id,
        transaction_type="BUY",
        amount=total_cost,
        balance_before=balance_before,
        balance_after=balance_after,
        reference_type="TRADE",
        reference_id=trade_id,
        description=f"Paper BUY {symbol} qty={quantity} price={current_close}",
    )

    update_strategy_run(cur, strategy_run_id, "BUY", f"Breakout + trend BUY uitgevoerd ({asset_type})")
    log_bot("INFO", f"symbol={symbol} action=BUY qty={quantity} price={current_close}", db_log=True)
    return True


def should_sell(open_position: dict, analysis: dict) -> tuple[bool, str]:
    avg_price = q8(open_position["avg_price"])
    current_close = analysis["current_close"]
    current_high = analysis["current_high"]
    trend_state = analysis["trend_state"]
    trend_score = analysis["trend_score"]

    if avg_price <= 0:
        return False, "ONGELDIGE_AVG_PRICE"

    profit_pct = q4(((current_close / avg_price) - 1) * 100)

    if profit_pct <= DEFAULT_STOP_LOSS_PCT:
        return True, f"STOP_LOSS {profit_pct:.4f}%"

    if profit_pct >= DEFAULT_TAKE_PROFIT_PCT and trend_score <= 1:
        return True, f"TAKE_PROFIT_WEAK_TREND {profit_pct:.4f}%"

    if profit_pct >= DEFAULT_TRAILING_TRIGGER_PCT:
        trailing_stop_price = current_high * (Decimal("1") - (DEFAULT_TRAILING_GAP_PCT / Decimal("100")))
        if current_close <= trailing_stop_price:
            return True, f"TRAILING_STOP current={current_close} trailing_stop={trailing_stop_price}"

    if trend_state == "DOWN" and trend_score <= 1:
        return True, f"TREND_REVERSAL score={trend_score}"

    return False, "HOLD"


def should_buy(breakout: int, analysis: dict) -> tuple[bool, str]:
    if breakout != 1:
        return False, "NO_BREAKOUT"

    trend_score = analysis["trend_score"]
    trend_state = analysis["trend_state"]
    rsi14 = analysis["rsi14"] if analysis["rsi14"] is not None else q4(50)

    if trend_state != "UP":
        return False, f"TREND_NOT_UP score={trend_score}"

    if trend_score < DEFAULT_MIN_TREND_SCORE:
        return False, f"TREND_TOO_WEAK score={trend_score} min={DEFAULT_MIN_TREND_SCORE}"

    if rsi14 > DEFAULT_MAX_RSI_BUY:
        return False, f"RSI_TOO_HIGH rsi={rsi14} max={DEFAULT_MAX_RSI_BUY}"

    return True, f"BUY_SIGNAL breakout=1 score={trend_score} rsi={rsi14}"


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

    try:
        asset_type = str(symbol_row.get("asset_type") or "STOCK").upper().strip()
        data_symbol = str(symbol_row.get("data_symbol") or symbol).strip()
        order_symbol = str(symbol_row.get("order_symbol") or symbol).strip()

        window = int(settings["breakout_window"])
        amount_eur = q2(settings["amount_per_trade_eur"])
        trade_enabled = int(settings["trade_enabled"])
        max_open_positions = int(settings["max_open_positions"])

        log_bot(
            "INFO",
            f"symbol={symbol} status=START data_symbol={data_symbol} order_symbol={order_symbol}"
        )

        day_data = fetch_breakout_data(data_symbol, days=window + 1)
        intraday = fetch_intraday_data(data_symbol)
        analysis = compute_indicators(intraday)

        closes_daily = day_data["Close"].dropna()
        highs_daily = day_data["High"].dropna()

        if len(closes_daily) < 2 or len(highs_daily) < 2:
            raise ValueError(f"Onvoldoende dagdata voor breakout-bepaling voor {data_symbol}")

        current_close = analysis["current_close"]

        if len(highs_daily) > window:
            previous_high_raw = highs_daily.iloc[-(window + 1):-1].max()
        else:
            previous_high_raw = highs_daily.iloc[:-1].max()

        previous_high = q8(to_float(previous_high_raw) if previous_high_raw is not None else 0)
        breakout = 1 if current_close > previous_high else 0

        db = get_db()
        cur = db.cursor()

        open_position = get_open_position(cur, symbol)
        has_open_position = 1 if open_position else 0

        strategy_run_id = create_strategy_run(cur, symbol, current_close, previous_high, breakout)

        insert_snapshot(
            cur=cur,
            symbol=symbol,
            analysis=analysis,
            breakout=breakout,
            previous_high=previous_high,
            has_open_position=has_open_position,
        )

        if open_position:
            sell_ok, sell_reason = should_sell(open_position, analysis)
            avg_price = q8(open_position["avg_price"])

            if sell_ok:
                execute_sell_not_implemented(cur, symbol, sell_reason, strategy_run_id)
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

        buy_ok, buy_reason = should_buy(breakout, analysis)
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
                f"symbol={symbol} status=OK outcome=BUY breakout={breakout} reason={buy_reason}"
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
            f"max_open_positions={settings['max_open_positions']}"
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