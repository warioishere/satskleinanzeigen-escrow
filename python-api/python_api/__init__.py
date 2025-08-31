import json
import hmac
import hashlib
import threading
import queue
import time
import uuid
from typing import Any, Dict, Optional

from fastapi import FastAPI, Request, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from starlette.middleware.base import BaseHTTPMiddleware
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
    WEBHOOK_RETRIES,
    WEBHOOK_BACKOFF,
    WEBHOOK_QUEUE_SIZE,
    WEBHOOK_COUNTER,
    STUCK_ORDER_HOURS,
    STUCK_CHECK_INTERVAL,
    SIGNING_DEADLINE_DAYS,
    STATE_TRANSITIONS,
    STUCK_COUNTER,
    log,
    req_id_var,
    order_id_var,
    actor_var,
    require_api_key,
    PENDING_SIG,
)
from .rpc import rpc

try:
    import sentry_sdk
except Exception:  # pragma: no cover - optional
    sentry_sdk = None


db.init_db()


def update_pending_gauge():
    try:
        PENDING_SIG.set(db.count_pending_signatures())
    except Exception:
        PENDING_SIG.set(0)


update_pending_gauge()


_webhook_q: queue.Queue = queue.Queue()


def woo_callback(payload: Dict[str, Any]):
    if not (WOO_CALLBACK_URL and WOO_HMAC_SECRET):
        return
    body = json.dumps(payload)
    sig = hmac.new(WOO_HMAC_SECRET.encode(), body.encode(), hashlib.sha256).hexdigest()
    _webhook_q.put((body, sig, 0))
    WEBHOOK_QUEUE_SIZE.set(_webhook_q.qsize())


def _webhook_worker():  # pragma: no cover - network interaction
    import requests

    while True:
        body, sig, retry = _webhook_q.get()
        try:
            r = requests.post(
                WOO_CALLBACK_URL,
                data=body,
                headers={"X-Signature": sig, "Content-Type": "application/json"},
                timeout=10,
            )
            if r.status_code >= 400:
                raise Exception(f"status {r.status_code}")
            WEBHOOK_COUNTER.labels(status="ok").inc()
        except Exception:
            WEBHOOK_COUNTER.labels(status="fail").inc()
            if retry < WEBHOOK_RETRIES:
                time.sleep(WEBHOOK_BACKOFF ** retry)
                _webhook_q.put((body, sig, retry + 1))
        finally:
            WEBHOOK_QUEUE_SIZE.set(_webhook_q.qsize())


def advance_state(order: Dict[str, Any], new_state: str, confirmations: Optional[int] = None) -> bool:
    cur = order.get("state") or "awaiting_deposit"
    if new_state == cur:
        if confirmations is not None:
            db.update_state(order["order_id"], cur, confirmations)
            update_pending_gauge()
        return False
    allowed = STATE_TRANSITIONS.get(cur, set())
    if new_state not in allowed:
        raise HTTPException(400, f"invalid state transition {cur}->{new_state}")
    deadline = None
    if new_state in {"escrow_funded", "signing"}:
        deadline = int(time.time()) + SIGNING_DEADLINE_DAYS * 86400
    db.update_state(order["order_id"], new_state, confirmations, deadline)
    order["state"] = new_state
    if deadline is not None:
        order["deadline_ts"] = deadline
    else:
        order["deadline_ts"] = None
    update_pending_gauge()
    return True


def _stuck_worker():  # pragma: no cover - background worker
    from .models import FinalizeReq, BroadcastReq
    from .routes.psbt import psbt_finalize
    from .routes.admin import tx_broadcast

    while True:
        try:
            now = int(time.time())
            orders = db.list_orders_by_states(["awaiting_deposit", "signing"])
            for o in orders:
                age_h = (now - (o.get("created_at") or now)) / 3600
                if age_h > STUCK_ORDER_HOURS:
                    state = o.get("state") or "unknown"
                    STUCK_COUNTER.labels(state=state).inc()
                    log.warning("order_stuck", order_id=o.get("order_id"), state=state, age_hours=age_h)
                    if sentry_sdk:
                        sentry_sdk.capture_message(
                            f"order {o.get('order_id')} stuck in {state} for {age_h:.1f}h"
                        )
                if (o.get("state") == "signing" and o.get("deadline_ts") and now > int(o["deadline_ts"])):
                    try:
                        parts = db.get_partials(o["order_id"])
                        if not parts:
                            continue
                        merged = rpc("combinepsbt", [parts])
                        pre_dec = rpc("decodepsbt", [merged])
                        pre_sig = sum(len(i.get("partial_signatures", {})) for i in pre_dec.get("inputs", []))
                        signed = rpc("walletprocesspsbt", [merged])
                        signed_psbt = signed.get("psbt", merged)
                        post_dec = rpc("decodepsbt", [signed_psbt])
                        post_sig = sum(len(i.get("partial_signatures", {})) for i in post_dec.get("inputs", []))
                        if post_sig == pre_sig:
                            log.info("watch_only_no_signatures", order_id=o["order_id"], sign_count=post_sig)
                        if post_sig < 2:
                            STUCK_COUNTER.labels(state="insufficient_signatures").inc()
                            log.info("deadline_escalation_skipped", order_id=o["order_id"], sign_count=post_sig)
                            continue
                        final_state = "completed" if o.get("output_type") != "refund" else "refunded"
                        fin = psbt_finalize(FinalizeReq(order_id=o["order_id"], psbt=signed_psbt, state=final_state))
                        tx_broadcast(BroadcastReq(order_id=o["order_id"], hex=fin["hex"], state=final_state))
                        log.info("deadline_escalated", order_id=o["order_id"], state=final_state)
                    except Exception as e:
                        log.error("deadline_escalation_failed", order_id=o.get("order_id"), error=str(e))
        except Exception as e:
            log.error("stuck_worker_error", error=str(e))
        time.sleep(STUCK_CHECK_INTERVAL)


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


def _rate_limit_key(request: Request) -> str:
    return request.headers.get("x-api-key") or get_remote_address(request)


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


from .routes import orders, psbt, admin

app.include_router(admin.router)
app.include_router(orders.router)
app.include_router(psbt.router)

from .routes.psbt import psbt_finalize  # re-export for tests
from .routes.admin import tx_broadcast  # re-export for tests

__all__ = [
    "app",
    "rpc",
    "advance_state",
    "woo_callback",
    "update_pending_gauge",
    "_webhook_q",
    "psbt_finalize",
    "tx_broadcast",
]
