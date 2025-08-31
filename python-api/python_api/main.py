import threading
from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from slowapi import Limiter, _rate_limit_exceeded_handler
from slowapi.util import get_remote_address
from slowapi.errors import RateLimitExceeded
from slowapi.middleware import SlowAPIMiddleware

import db
from .config import (
    ALLOW_ORIGINS,
    RATE_LIMIT,
    WOO_CALLBACK_URL,
    WOO_HMAC_SECRET,
)
from .workers import update_pending_gauge, _webhook_worker, _stuck_worker
from .logging import LoggingMiddleware
from .routes import orders, psbt, admin


def _rate_limit_key(request: Request) -> str:
    return request.headers.get("x-api-key") or get_remote_address(request)


db.init_db()
update_pending_gauge()

app = FastAPI(title="Escrow API (2-of-3 P2WSH, PSBT)")
app.add_middleware(
    CORSMiddleware,
    allow_origins=ALLOW_ORIGINS,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)
limiter = Limiter(key_func=_rate_limit_key, default_limits=[RATE_LIMIT])
app.state.limiter = limiter
app.add_exception_handler(RateLimitExceeded, _rate_limit_exceeded_handler)
app.add_middleware(SlowAPIMiddleware)
app.add_middleware(LoggingMiddleware)


@app.on_event("startup")
def _startup_worker():
    if WOO_CALLBACK_URL and WOO_HMAC_SECRET:
        threading.Thread(target=_webhook_worker, daemon=True).start()
    threading.Thread(target=_stuck_worker, daemon=True).start()


app.include_router(admin.router)
app.include_router(orders.router)
app.include_router(psbt.router)
