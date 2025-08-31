import sys
import logging
import time
import uuid
from contextvars import ContextVar
from typing import Optional

import structlog
from fastapi import Request
from starlette.middleware.base import BaseHTTPMiddleware


logging.basicConfig(stream=sys.stdout, format="%(message)s", level=logging.INFO)
structlog.configure(
    processors=[
        structlog.processors.TimeStamper(fmt="iso"),
        structlog.processors.add_log_level,
        structlog.processors.JSONRenderer(),
    ],
    logger_factory=structlog.stdlib.LoggerFactory(),
)
log = structlog.get_logger()

req_id_var: ContextVar[Optional[str]] = ContextVar("request_id", default=None)
order_id_var: ContextVar[Optional[str]] = ContextVar("order_id", default=None)
actor_var: ContextVar[Optional[str]] = ContextVar("actor", default=None)


class LoggingMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        request_id = request.headers.get("X-Request-ID") or str(uuid.uuid4())
        req_id_var.set(request_id)
        actor = request.headers.get("X-Actor")
        if actor:
            actor_var.set(actor)
        start = time.time()
        response = await call_next(request)
        duration = time.time() - start
        log.info(
            "request",
            request_id=request_id,
            method=request.method,
            path=str(request.url.path),
            status=response.status_code,
            duration=duration,
            order_id=order_id_var.get(),
            actor=actor_var.get(),
        )
        order_id_var.set(None)
        actor_var.set(None)
        req_id_var.set(None)
        return response
