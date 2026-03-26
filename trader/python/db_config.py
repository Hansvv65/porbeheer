"""Centrale databaseconfiguratie voor Trading PY scripts."""
from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class DBConfig:
    host: str = os.getenv("TRADING_DB_HOST", "localhost")
    port: int = int(os.getenv("TRADING_DB_PORT", "3306"))
    user: str = os.getenv("TRADING_DB_USER", "trading_user")
    password: str = os.getenv("TRADING_DB_PASS", "")
    database: str = os.getenv("TRADING_DB_NAME", "trading_db")

    def as_mysql_kwargs(self) -> dict:
        return {
            "host": self.host,
            "port": self.port,
            "user": self.user,
            "password": self.password,
            "database": self.database,
        }


DB_CONFIG = DBConfig()
