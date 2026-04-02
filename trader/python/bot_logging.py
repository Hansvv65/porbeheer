#!/usr/bin/env python3
from __future__ import annotations

import logging


def log_bot(
    logger: logging.Logger,
    bot_name: str,
    stage: str,
    message: str = "",
    level: str = "info"
) -> None:
    """
    Uniform logging format voor alle trading bots.

    Voorbeeld output:

    BREAKOUT START symbols=15
    BREAKOUT INFO symbol=ASML breakout=1 action=BUY
    BREAKOUT END status=OK trades=2
    BREAKOUT ERROR status=FAILED error=...
    """

    text = f"{bot_name} {stage}"

    if message:
        text += f" {message}"

    level = (level or "info").lower()

    if level == "error":
        logger.error(text)

    elif level == "warning":
        logger.warning(text)

    else:
        logger.info(text)