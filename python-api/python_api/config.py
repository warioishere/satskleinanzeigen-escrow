import os
import sys
import logging
from typing import Optional, Any, Dict
from contextvars import ContextVar

from dotenv import load_dotenv
import structlog
from fastapi import Header, HTTPException
from prometheus_client import Histogram, Counter, Gauge, REGISTRY

load_dotenv()

BTC_CORE_URL     = os.getenv("BTC_CORE_URL", "http://127.0.0.1:8332/")
BTC_CORE_USER    = os.getenv("BTC_CORE_USER", "")
BTC_CORE_PASS    = os.getenv("BTC_CORE_PASS", "")
BTC_CORE_WALLET  = os.getenv("BTC_CORE_WALLET", "escrowwatch")
API_KEYS         = {k.strip() for k in os.getenv("API_KEYS", "").split(",") if k.strip()}
API_KEY_REVOKED  = {k.strip() for k in os.getenv("API_KEY_REVOKED", "").split(",") if k.strip()}

_origins_env = os.getenv("ALLOW_ORIGINS")
if not _origins_env:
    raise RuntimeError("ALLOW_ORIGINS env var required")
ALLOW_ORIGINS    = [o.strip() for o in _origins_env.split(",") if o.strip()]
WOO_CALLBACK_URL = os.getenv("WOO_CALLBACK_URL", "")
WOO_HMAC_SECRET  = os.getenv("WOO_HMAC_SECRET", "")
WEBHOOK_RETRIES  = int(os.getenv("WEBHOOK_RETRIES", "3"))
WEBHOOK_BACKOFF  = float(os.getenv("WEBHOOK_BACKOFF", "2"))
STUCK_ORDER_HOURS = int(os.getenv("STUCK_ORDER_HOURS", "24"))
STUCK_CHECK_INTERVAL = int(os.getenv("STUCK_CHECK_INTERVAL", "600"))
SIGNING_DEADLINE_DAYS = int(os.getenv("SIGNING_DEADLINE_DAYS", "7"))
RATE_LIMIT = os.getenv("RATE_LIMIT", "100/minute")

# ---- Prometheus metrics ----
def _metric(name, factory):
    existing = REGISTRY._names_to_collectors.get(name)
    return existing if existing else factory()

RPC_HIST = _metric('rpc_duration_seconds', lambda: Histogram('rpc_duration_seconds', 'Bitcoin Core RPC duration', ['method']))
WEBHOOK_COUNTER = _metric('webhook_total', lambda: Counter('webhook_total', 'Webhook deliveries', ['status']))
PENDING_SIG = _metric('pending_signatures', lambda: Gauge('pending_signatures', 'Open PSBT signatures'))
STUCK_COUNTER = _metric('stuck_orders_total', lambda: Counter('stuck_orders_total', 'Orders stuck beyond threshold', ['state']))
BROADCAST_FAIL = _metric('broadcast_fail_total', lambda: Counter('broadcast_fail_total', 'Failed transaction broadcasts'))
WEBHOOK_QUEUE_SIZE = _metric('webhook_queue_size', lambda: Gauge('webhook_queue_size', 'Pending webhooks in queue'))

# ---- logging ----
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

# ---- State machine ----
STATES = [
    "awaiting_deposit",
    "escrow_funded",
    "signing",
    "completed",
    "refunded",
    "dispute",
]

STATE_TRANSITIONS = {
    "awaiting_deposit": {"escrow_funded"},
    "escrow_funded": {"signing", "dispute"},
    "signing": {"completed", "refunded", "dispute"},
    "completed": set(),
    "refunded": set(),
    "dispute": set(),
}

# ---- Simple API-Key dependency ----
def require_api_key(x_api_key: Optional[str] = Header(None)):
    if API_KEYS:
        if not x_api_key or x_api_key not in API_KEYS or x_api_key in API_KEY_REVOKED:
            raise HTTPException(status_code=401, detail="missing/invalid api key")
