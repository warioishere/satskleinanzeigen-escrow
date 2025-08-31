import os, json, hmac, hashlib, time, threading, queue, uuid, logging, sys
from typing import Any, Dict, List, Optional
from fastapi import FastAPI, HTTPException, Header, Depends, Request, Response
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import PlainTextResponse
from pydantic import BaseModel, Field, field_validator, constr
import re, base64
import requests
from dotenv import load_dotenv
import structlog
from contextvars import ContextVar
from starlette.middleware.base import BaseHTTPMiddleware
from prometheus_client import Histogram, Counter, Gauge, generate_latest, CONTENT_TYPE_LATEST, REGISTRY
from slowapi import Limiter, _rate_limit_exceeded_handler
from slowapi.util import get_remote_address
from slowapi.errors import RateLimitExceeded
from slowapi.middleware import SlowAPIMiddleware
import db

try:
    import sentry_sdk
except Exception:
    sentry_sdk = None

load_dotenv()

BTC_CORE_URL     = os.getenv("BTC_CORE_URL", "http://127.0.0.1:8332/")
BTC_CORE_USER    = os.getenv("BTC_CORE_USER", "")
BTC_CORE_PASS    = os.getenv("BTC_CORE_PASS", "")
BTC_CORE_WALLET  = os.getenv("BTC_CORE_WALLET", "escrowwatch")
PORT             = int(os.getenv("PORT", "8080"))
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
SENTRY_DSN = os.getenv("SENTRY_DSN")
RATE_LIMIT = os.getenv("RATE_LIMIT", "100/minute")
if SENTRY_DSN and sentry_sdk:
    sentry_sdk.init(dsn=SENTRY_DSN)

db.init_db()

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


def update_pending_gauge():
    try:
        PENDING_SIG.set(db.count_pending_signatures())
    except Exception:
        PENDING_SIG.set(0)

update_pending_gauge()

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

# ---- FastAPI app ----
app = FastAPI(title="Escrow API (2-of-3 P2WSH, PSBT)")
app.add_middleware(
    CORSMiddleware,
    allow_origins=ALLOW_ORIGINS,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


def _rate_limit_key(request: Request) -> str:
    return request.headers.get("x-api-key") or get_remote_address(request)


limiter = Limiter(key_func=_rate_limit_key, default_limits=[RATE_LIMIT])
app.state.limiter = limiter
app.add_exception_handler(RateLimitExceeded, _rate_limit_exceeded_handler)
app.add_middleware(SlowAPIMiddleware)


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


app.add_middleware(LoggingMiddleware)

@app.on_event("startup")
def _startup_worker():
    if WOO_CALLBACK_URL and WOO_HMAC_SECRET:
        threading.Thread(target=_webhook_worker, daemon=True).start()
    threading.Thread(target=_stuck_worker, daemon=True).start()

# ---- Simple API-Key dependency (optional) ----
def require_api_key(x_api_key: Optional[str] = Header(None)):
    if API_KEYS:
        if not x_api_key or x_api_key not in API_KEYS or x_api_key in API_KEY_REVOKED:
            raise HTTPException(status_code=401, detail="missing/invalid api key")

# ---- Bitcoin Core RPC ----
def rpc(method: str, params: List[Any] = None) -> Any:
    bound = log.bind(
        request_id=req_id_var.get(),
        order_id=order_id_var.get(),
        actor=actor_var.get(),
        rpc_method=method,
    )
    url = BTC_CORE_URL.rstrip("/") + f"/wallet/{BTC_CORE_WALLET}"
    payload = {"jsonrpc":"1.0","id":"escrow","method":method,"params":params or []}
    auth = (BTC_CORE_USER, BTC_CORE_PASS) if BTC_CORE_USER or BTC_CORE_PASS else None
    start = time.time()
    bound.info("rpc_start", params=params)
    r = None
    try:
        r = requests.post(url, json=payload, auth=auth, timeout=25)
        j = r.json()
    except Exception as e:
        bound.error("rpc_error", error=str(e), duration=time.time()-start)
        raise HTTPException(status_code=502, detail=f"Core RPC bad response ({getattr(r, 'status_code', 'n/a')})")
    if j.get("error"):
        bound.error("rpc_error", error=j["error"], duration=time.time()-start)
        raise HTTPException(status_code=500, detail=j["error"]["message"])
    duration = time.time() - start
    bound.info("rpc_success", duration=duration)
    RPC_HIST.labels(method=method).observe(duration)
    return j["result"]

# ---- Models ----
class Party(BaseModel):
    xpub: constr(strip_whitespace=True, pattern=r'^[A-Za-z0-9]+$')

OrderID = constr(pattern=r'^[A-Za-z0-9_-]{1,32}$')

class CreateOrderReq(BaseModel):
    order_id: OrderID
    buyer: Party
    seller: Party
    escrow: Party
    index: Optional[int] = Field(None, ge=0)
    min_conf: int = Field(2, ge=0, le=100)
    amount_sat: int = Field(..., ge=0)

class CreateOrderRes(BaseModel):
    escrow_address: str
    descriptor: str
    watch_id: str

class StatusRes(BaseModel):
    funding: Optional[Dict[str, Any]] = None
    state: str
    deadline_ts: Optional[int] = None

class PSBTBuildReq(BaseModel):
    order_id: OrderID
    outputs: Dict[str, int]
    rbf: bool = True
    target_conf: int = Field(3, ge=1, le=100)

    @field_validator('outputs')
    def _check_outputs(cls, v):
        addr_re = re.compile(r'^(bc1|tb1)[0-9ac-hj-np-z]{8,87}$')
        for addr, amt in v.items():
            if not addr_re.match(addr):
                raise ValueError('invalid address')
            if not isinstance(amt, int) or amt <= 0 or amt > 2100000000000000:
                raise ValueError('invalid amount')
        return v

class PSBTRefundReq(BaseModel):
    order_id: OrderID
    address: constr(strip_whitespace=True, pattern=r'^(bc1|tb1)[0-9ac-hj-np-z]{8,87}$')
    rbf: bool = True
    target_conf: int = Field(3, ge=1, le=100)

class PayoutQuoteReq(BaseModel):
    address: constr(strip_whitespace=True, pattern=r'^(bc1|tb1)[0-9ac-hj-np-z]{8,87}$')
    rbf: bool = True
    target_conf: int = Field(3, ge=1, le=100)

class PayoutQuoteRes(BaseModel):
    payout_sat: int
    fee_sat: int

class PSBTRes(BaseModel):
    psbt: str

class MergeReq(BaseModel):
    order_id: Optional[OrderID] = None
    partials: List[str]

    @field_validator('partials')
    def _check_part(cls, v):
        for item in v:
            try:
                base64.b64decode(item, validate=True)
            except Exception:
                raise ValueError('invalid psbt fragment')
        return v

class FinalizeReq(BaseModel):
    order_id: Optional[OrderID] = None
    psbt: str
    state: str = Field("completed", pattern=r'^(completed|refunded|dispute)$')

class BroadcastReq(BaseModel):
    hex: str
    order_id: Optional[OrderID] = None
    state: str = Field("completed", pattern=r'^(completed|refunded|dispute)$')

class BumpFeeReq(BaseModel):
    order_id: OrderID
    target_conf: int = Field(..., ge=1, le=100)

class DecodeReq(BaseModel):
    psbt: str

class DecodeRes(BaseModel):
    sign_count: int
    outputs: Dict[str, int]
    fee_sat: int

# ---- Webhook worker ----

_webhook_q: queue.Queue = queue.Queue()

def _webhook_worker():
    while True:
        payload = _webhook_q.get()
        WEBHOOK_QUEUE_SIZE.set(_webhook_q.qsize())
        order_id = payload.get("order_id")
        if not order_id:
            _webhook_q.task_done()
            WEBHOOK_QUEUE_SIZE.set(_webhook_q.qsize())
            continue
        meta = db.get_order(order_id)
        if payload.get("event") != "escrow_funded" and meta and meta.get("last_webhook_ts"):
            _webhook_q.task_done()
            WEBHOOK_QUEUE_SIZE.set(_webhook_q.qsize())
            continue
        success = False
        for attempt in range(WEBHOOK_RETRIES):
            body = json.dumps(payload, separators=(",",":")).encode()
            ts   = str(int(time.time()))
            sig  = hmac.new(WOO_HMAC_SECRET.encode(), ts.encode() + body, hashlib.sha256).hexdigest()
            try:
                r = requests.post(WOO_CALLBACK_URL, data=body, headers={
                    "content-type":"application/json",
                    "x-weo-sign": sig,
                    "x-weo-ts": ts
                }, timeout=10)
                if 200 <= r.status_code < 300:
                    if payload.get("event") != "escrow_funded":
                        db.set_last_webhook_ts(order_id, int(ts))
                    success = True
                    break
            except Exception:
                pass
            time.sleep(WEBHOOK_BACKOFF ** attempt)
        if success:
            WEBHOOK_COUNTER.labels(status="success").inc()
        else:
            WEBHOOK_COUNTER.labels(status="error").inc()
        _webhook_q.task_done()
        WEBHOOK_QUEUE_SIZE.set(_webhook_q.qsize())

def woo_callback(payload: Dict[str, Any]):
    if not (WOO_CALLBACK_URL and WOO_HMAC_SECRET):
        return
    _webhook_q.put(payload)
    WEBHOOK_QUEUE_SIZE.set(_webhook_q.qsize())


def _stuck_worker():
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
                        signed = rpc("walletprocesspsbt", [merged])
                        signed_psbt = signed.get("psbt", merged)
                        final_state = "completed" if o.get("output_type") != "refund" else "refunded"
                        fin = psbt_finalize(FinalizeReq(order_id=o["order_id"], psbt=signed_psbt, state=final_state))
                        tx_broadcast(BroadcastReq(order_id=o["order_id"], hex=fin["hex"], state=final_state))
                        log.info("deadline_escalated", order_id=o["order_id"], state=final_state)
                    except Exception as e:
                        log.error("deadline_escalation_failed", order_id=o.get("order_id"), error=str(e))
        except Exception as e:
            log.error("stuck_worker_error", error=str(e))
        time.sleep(STUCK_CHECK_INTERVAL)


# ---- Helpers ----

def build_descriptor(xpub_b: str, xpub_s: str, xpub_e: str, index: int) -> str:
    # sortedmulti sortiert intern – Reihenfolge ist egal; wir geben sie wie geliefert weiter
    # Für MVP leiten wir Childs über /0/index ab
    return f"wsh(sortedmulti(2,{xpub_b}/0/{index},{xpub_s}/0/{index},{xpub_e}/0/{index}))"

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

def find_utxos_for_label(label: str, min_conf: int) -> List[Dict[str,Any]]:
    # listunspent ignoriert Label-Filter parameter in manchen Cores; wir filtern hier
    utxos = rpc("listunspent", [min_conf, 9999999, [], True, {}])
    return [u for u in utxos if (u.get("label") or "") == label]

# ---- Routes ----

@app.get("/live")
def live():
    return {"ok": True}

@app.get("/health", dependencies=[Depends(require_api_key)])
def health(response: Response):
    db_ok = True
    try:
        conn = db.get_conn()
        conn.execute("SELECT 1")
        conn.close()
    except Exception:
        db_ok = False

    rpc_ok = True
    try:
        rpc("getblockchaininfo")
    except Exception:
        rpc_ok = False

    qlen = _webhook_q.qsize()
    WEBHOOK_QUEUE_SIZE.set(qlen)
    ok = db_ok and rpc_ok
    if not ok:
        response.status_code = 503
    return {"ok": ok, "db": db_ok, "rpc": rpc_ok, "webhook_queue": qlen}


@app.get("/metrics", dependencies=[Depends(require_api_key)])
def metrics():
    return PlainTextResponse(generate_latest(), media_type=CONTENT_TYPE_LATEST)

@app.post("/orders", response_model=CreateOrderRes, dependencies=[Depends(require_api_key)])
def create_order(body: CreateOrderReq):
    order_id_var.set(body.order_id)
    existing = db.get_order(body.order_id)
    if existing:
        addr = rpc("deriveaddresses", [existing["descriptor"], [existing["index"], existing["index"]]])[0]
        return CreateOrderRes(
            escrow_address=addr,
            descriptor=existing["descriptor"],
            watch_id=f"escrow_{body.order_id}_{existing['index']}"
        )

    idx = body.index if body.index is not None else db.next_index()
    desc = build_descriptor(body.buyer.xpub, body.seller.xpub, body.escrow.xpub, idx)
    info = rpc("getdescriptorinfo", [desc])
    desc_ck = f"{desc}#{info['checksum']}"
    label = f"escrow:{body.order_id}"

    rpc("importdescriptors", [[{
        "desc": desc_ck,
        "timestamp": "now",
        "label": label,
        "internal": False,
        "active": False,
        "range": [idx, idx]
    }]])
    addr = rpc("deriveaddresses", [desc_ck, [idx, idx]])[0]

    db.upsert_order(body.order_id, desc_ck, idx, body.min_conf, label, body.amount_sat)
    return CreateOrderRes(
        escrow_address=addr,
        descriptor=desc_ck,
        watch_id=f"escrow_{body.order_id}_{idx}"
    )

@app.get("/orders/{order_id}/status", response_model=StatusRes, dependencies=[Depends(require_api_key)])
def order_status(order_id: str):
    order_id_var.set(order_id)
    meta = db.get_order(order_id)
    if not meta:
        return StatusRes(state="awaiting_deposit")
    utxos = find_utxos_for_label(meta["label"], 0)
    if not utxos:
        return StatusRes(state=meta["state"], deadline_ts=meta.get("deadline_ts"))

    total_sat = 0
    funding_utxos = []
    min_conf = None
    for u in utxos:
        tx = rpc("gettransaction", [u["txid"]])
        conf = int(tx.get("confirmations", 0))
        sat = int(round(u.get("amount", 0) * 1e8))
        total_sat += sat
        funding_utxos.append({
            "txid": u["txid"],
            "vout": u["vout"],
            "value_sat": sat,
            "confirmations": conf,
        })
        if min_conf is None or conf < min_conf:
            min_conf = conf

    first = utxos[0]
    db.update_funding(order_id, first["txid"], first["vout"], min_conf or 0)

    state = meta["state"]
    expected = int(meta.get("amount_sat") or 0)
    if expected > 0 and min_conf is not None and min_conf >= int(meta["min_conf"]) and total_sat >= expected:
        changed = advance_state(meta, "escrow_funded", min_conf)
        state = "escrow_funded"
        if changed:
            woo_callback({
                "order_id": order_id,
                "event": "escrow_funded",
                "utxos": funding_utxos,
                "total_sat": total_sat,
                "confs": min_conf,
            })

    res = StatusRes(
        funding={
            "utxos": funding_utxos,
            "total_sat": total_sat,
            "confirmations": min_conf,
        },
        state=state,
        deadline_ts=meta.get("deadline_ts"),
    )
    return res

@app.post("/orders/{order_id}/payout_quote", response_model=PayoutQuoteRes, dependencies=[Depends(require_api_key)])
def payout_quote(order_id: str, body: PayoutQuoteReq):
    order_id_var.set(order_id)
    meta = db.get_order(order_id)
    if not meta:
        raise HTTPException(404, "order not found")
    utxos = find_utxos_for_label(meta["label"], int(meta["min_conf"]))
    if not utxos:
        raise HTTPException(400, "no funded utxo")
    ins = [{"txid": u["txid"], "vout": u["vout"]} for u in utxos]
    opts = {
        "includeWatching": True,
        "replaceable": body.rbf,
        "conf_target": body.target_conf,
        "subtractFeeFromOutputs": [0],
    }
    psbt = rpc("walletcreatefundedpsbt", [ins, [{body.address: 0}], 0, opts])
    dec = rpc("decodepsbt", [psbt])
    vout0 = dec.get("tx", {}).get("vout", [{}])[0]
    payout_sat = int(round(vout0.get("value", 0) * 1e8))
    in_total = sum(int(round(u.get("amount", 0) * 1e8)) for u in utxos)
    fee_sat = in_total - payout_sat
    return PayoutQuoteRes(payout_sat=payout_sat, fee_sat=fee_sat)

@app.post("/psbt/build", response_model=PSBTRes, dependencies=[Depends(require_api_key)])
def psbt_build(body: PSBTBuildReq):
    order_id_var.set(body.order_id)
    meta = db.get_order(body.order_id)
    if not meta:
        raise HTTPException(404, "order not found")
    utxos = find_utxos_for_label(meta["label"], int(meta["min_conf"]))
    if not utxos:
        raise HTTPException(400, "no funded utxo")

    ins = [{"txid": u["txid"], "vout": u["vout"]} for u in utxos]
    outs_btc: List[Dict[str,float]] = [{addr: sats/1e8} for addr, sats in body.outputs.items()]
    psbt = rpc("createpsbt", [ins, outs_btc, 0, True])
    db.set_outputs(body.order_id, body.outputs, "payout")
    advance_state(meta, "signing")
    return PSBTRes(psbt=psbt)

@app.post("/psbt/build_refund", response_model=PSBTRes, dependencies=[Depends(require_api_key)])
def psbt_build_refund(body: PSBTRefundReq):
    order_id_var.set(body.order_id)
    meta = db.get_order(body.order_id)
    if not meta:
        raise HTTPException(404, "order not found")
    utxos = find_utxos_for_label(meta["label"], int(meta["min_conf"]))
    if not utxos:
        raise HTTPException(400, "no funded utxo")
    ins = [{"txid": u["txid"], "vout": u["vout"]} for u in utxos]
    opts = {
        "includeWatching": True,
        "replaceable": body.rbf,
        "conf_target": body.target_conf,
        "subtractFeeFromOutputs": [0],
    }
    res = rpc("walletcreatefundedpsbt", [ins, {body.address: 0}, 0, opts])
    if res.get("changepos", -1) != -1:
        raise HTTPException(400, "unexpected change output")
    psbt = res.get("psbt")
    dec = rpc("decodepsbt", [psbt])
    outs = dec.get("tx", {}).get("vout", [])
    if len(outs) != 1:
        raise HTTPException(400, "unexpected outputs")
    addrs = outs[0].get("scriptPubKey", {}).get("addresses", [])
    if len(addrs) != 1 or addrs[0] != body.address:
        raise HTTPException(400, "outputs mismatch")
    val_sat = int(round(outs[0].get("value", 0) * 1e8))
    db.set_outputs(body.order_id, {body.address: val_sat}, "refund")
    advance_state(meta, "signing")
    return PSBTRes(psbt=psbt)

@app.post("/psbt/merge", response_model=PSBTRes, dependencies=[Depends(require_api_key)])
def psbt_merge(body: MergeReq):
    if not body.partials:
        raise HTTPException(400, "no partials")
    merged_list = body.partials
    if body.order_id:
        order_id_var.set(body.order_id)
        existing = db.get_order(body.order_id)
        if not existing:
            raise HTTPException(404, "order not found")
        prev = db.get_partials(body.order_id)
        new_parts = [p for p in body.partials if p not in prev]
        merged_list = prev + new_parts
        db.save_partials(body.order_id, merged_list)
        update_pending_gauge()
    merged = rpc("combinepsbt", [merged_list])
    return PSBTRes(psbt=merged)

@app.post("/psbt/decode", response_model=DecodeRes, dependencies=[Depends(require_api_key)])
def psbt_decode(body: DecodeReq):
    dec = rpc("decodepsbt", [body.psbt])
    vout = dec.get("tx", {}).get("vout", [])
    outs: Dict[str, int] = {}
    for o in vout:
        addrs = o.get("scriptPubKey", {}).get("addresses", [])
        if addrs:
            outs[addrs[0]] = int(round(o.get("value", 0) * 1e8))
    ana = rpc("analyzepsbt", [body.psbt])
    fee_sat = int(round(ana.get("fee", 0) * 1e8)) if ana.get("fee") is not None else 0
    inputs = dec.get("inputs", [])
    count = 0
    if inputs:
        sigs = inputs[0].get("partial_signatures") or {}
        count = len(sigs)
    return DecodeRes(sign_count=count, outputs=outs, fee_sat=fee_sat)

@app.post("/psbt/finalize", dependencies=[Depends(require_api_key)])
def psbt_finalize(body: FinalizeReq):
    meta = None
    if body.order_id:
        order_id_var.set(body.order_id)
        meta = db.get_order(body.order_id)
        if not meta:
            raise HTTPException(404, "order not found")

    if not body.psbt:
        if meta and body.state == "dispute":
            advance_state(meta, "dispute")
            return {"hex": ""}
        raise HTTPException(400, "missing psbt")

    dec = rpc("decodepsbt", [body.psbt])
    tx = dec.get("tx") or {}
    vins = tx.get("vin", [])
    vouts = tx.get("vout", [])

    allowed = db.get_outputs(body.order_id) if body.order_id else {}
    if body.order_id and not allowed:
        log.error("psbt_missing_outputs", order_id=body.order_id)
        raise HTTPException(400, "missing stored outputs")
    in_total = 0
    for vin in vins:
        txid, vout = vin.get("txid"), vin.get("vout")
        txinfo = rpc("gettransaction", [txid])
        ok = False
        label = meta.get("label") if meta else None
        for det in txinfo.get("details", []):
            if det.get("vout") == vout and det.get("label") == label:
                ok = True
                break
        if meta and not ok:
            log.error("psbt_invalid_input", txid=txid, vout=vout)
            raise HTTPException(400, "input not from escrow label")
        seq = vin.get("sequence", 0xffffffff)
        if seq >= 0xfffffffe:
            log.error("psbt_non_rbf", txid=txid, sequence=seq)
            raise HTTPException(400, "RBF disabled")
        txout = rpc("gettxout", [txid, vout])
        if not txout or "value" not in txout:
            log.error("psbt_missing_txout", txid=txid, vout=vout)
            raise HTTPException(400, "missing input value")
        in_total += int(round(txout["value"] * 1e8))

    decoded_outputs: Dict[str, int] = {}
    for o in vouts:
        addrs = o.get("scriptPubKey", {}).get("addresses", [])
        if len(addrs) != 1:
            log.error("psbt_bad_output", output=o)
            raise HTTPException(400, "unexpected output")
        addr = addrs[0]
        val_sat = int(round(o.get("value", 0) * 1e8))
        decoded_outputs[addr] = val_sat

    if decoded_outputs != allowed:
        log.error("psbt_output_mismatch", expected=allowed, got=decoded_outputs)
        raise HTTPException(400, "outputs mismatch")

    out_total = sum(decoded_outputs.values())
    fee = in_total - out_total
    if fee < 0:
        log.error("psbt_negative_fee", fee=fee)
        raise HTTPException(400, "negative fee")
    if "fee" in dec and abs(int(round(dec["fee"] * 1e8)) - fee) > 1:
        log.error("psbt_fee_mismatch", psbt_fee=dec["fee"], calc_fee=fee / 1e8)
        raise HTTPException(400, "fee mismatch")

    if meta:
        funding_utxos = find_utxos_for_label(meta["label"], 0)
        funded_total = sum(int(round(u.get("amount", 0) * 1e8)) for u in funding_utxos)
        if out_total + fee > funded_total:
            log.error("psbt_exceeds_funding", funded=funded_total, spending=out_total + fee)
            raise HTTPException(400, "spends more than funded amount")

    fin = rpc("finalizepsbt", [body.psbt])
    if not fin.get("complete"):
        raise HTTPException(400, "not enough signatures")
    if meta and body.state not in {"completed", "refunded", "dispute"}:
        raise HTTPException(400, "invalid final state")
    return {"hex": fin["hex"], "fee_sat": fee}

@app.post("/tx/broadcast", dependencies=[Depends(require_api_key)])
def tx_broadcast(body: BroadcastReq):
    meta = None
    if body.order_id:
        order_id_var.set(body.order_id)
        meta = db.get_order(body.order_id)
        if not meta:
            raise HTTPException(404, "order not found")
    try:
        txid = rpc("sendrawtransaction", [body.hex])
    except HTTPException as e:
        BROADCAST_FAIL.inc()
        log.error("broadcast_fail", error=e.detail, order_id=body.order_id)
        if sentry_sdk:
            sentry_sdk.capture_message(
                f"broadcast failed for order {body.order_id or 'n/a'}: {e.detail}"
            )
        # Leave order in previous state if broadcast fails
        raise
    if body.order_id and meta:
        db.set_payout_txid(body.order_id, txid)
        if body.state not in {"completed", "refunded", "dispute"}:
            raise HTTPException(400, "invalid final state")
        advance_state(meta, body.state)
        if not meta.get("last_webhook_ts"):
            event = "settled" if body.state == "completed" else body.state
            woo_callback({"order_id": body.order_id, "event": event, "txid": txid})
    return {"txid": txid}


@app.post("/tx/bumpfee", dependencies=[Depends(require_api_key)])
def tx_bumpfee(body: BumpFeeReq):
    order_id_var.set(body.order_id)
    meta = db.get_order(body.order_id)
    if not meta or not meta.get("payout_txid"):
        raise HTTPException(404, "txid not found")
    res = rpc("bumpfee", [meta["payout_txid"], {"confTarget": body.target_conf}])
    new_txid = res.get("txid") if isinstance(res, dict) else res
    db.set_payout_txid(body.order_id, new_txid)
    return {"txid": new_txid}
