import os, json, hmac, hashlib, time, threading, queue
from typing import Any, Dict, List, Optional
from fastapi import FastAPI, HTTPException, Header, Depends
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
import requests
from dotenv import load_dotenv
import db

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

db.init_db()

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
    "escrow_funded": {"signing"},
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

@app.on_event("startup")
def _startup_worker():
    if WOO_CALLBACK_URL and WOO_HMAC_SECRET:
        threading.Thread(target=_webhook_worker, daemon=True).start()

# ---- Simple API-Key dependency (optional) ----
def require_api_key(x_api_key: Optional[str] = Header(None)):
    if API_KEYS:
        if not x_api_key or x_api_key not in API_KEYS or x_api_key in API_KEY_REVOKED:
            raise HTTPException(status_code=401, detail="missing/invalid api key")

# ---- Bitcoin Core RPC ----
def rpc(method: str, params: List[Any] = None) -> Any:
    url = BTC_CORE_URL.rstrip("/") + f"/wallet/{BTC_CORE_WALLET}"
    payload = {"jsonrpc":"1.0","id":"escrow","method":method,"params":params or []}
    auth = (BTC_CORE_USER, BTC_CORE_PASS) if BTC_CORE_USER or BTC_CORE_PASS else None
    r = requests.post(url, json=payload, auth=auth, timeout=25)
    try:
        j = r.json()
    except Exception:
        raise HTTPException(status_code=502, detail=f"Core RPC bad response ({r.status_code})")
    if j.get("error"):
        raise HTTPException(status_code=500, detail=j["error"]["message"])
    return j["result"]

# ---- Models ----
class Party(BaseModel):
    xpub: str

class CreateOrderReq(BaseModel):
    order_id: str
    buyer: Party
    seller: Party
    escrow: Party
    index: int
    min_conf: int = 2

class CreateOrderRes(BaseModel):
    escrow_address: str
    descriptor: str
    watch_id: str

class StatusRes(BaseModel):
    funding: Optional[Dict[str, Any]] = None
    state: str

class PSBTBuildReq(BaseModel):
    order_id: str
    outputs: Dict[str, int]   # address -> sats
    rbf: bool = True
    target_conf: int = 3

class PSBTRes(BaseModel):
    psbt: str

class MergeReq(BaseModel):
    order_id: Optional[str] = None
    partials: List[str]

class FinalizeReq(BaseModel):
    order_id: Optional[str] = None
    psbt: str
    state: str = "completed"

class BroadcastReq(BaseModel):
    hex: str
    order_id: Optional[str] = None
    state: str = "completed"

class BumpFeeReq(BaseModel):
    order_id: str
    target_conf: int

class DecodeReq(BaseModel):
    psbt: str

class DecodeRes(BaseModel):
    sign_count: int

# ---- Webhook worker ----

_webhook_q: queue.Queue = queue.Queue()

def _webhook_worker():
    while True:
        payload = _webhook_q.get()
        order_id = payload.get("order_id")
        if not order_id:
            _webhook_q.task_done()
            continue
        meta = db.get_order(order_id)
        if payload.get("event") != "escrow_funded" and meta and meta.get("last_webhook_ts"):
            _webhook_q.task_done()
            continue
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
                    break
            except Exception:
                pass
            time.sleep(WEBHOOK_BACKOFF ** attempt)
        _webhook_q.task_done()

def woo_callback(payload: Dict[str, Any]):
    if not (WOO_CALLBACK_URL and WOO_HMAC_SECRET):
        return
    _webhook_q.put(payload)


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
        return False
    allowed = STATE_TRANSITIONS.get(cur, set())
    if new_state not in allowed:
        raise HTTPException(400, f"invalid state transition {cur}->{new_state}")
    db.update_state(order["order_id"], new_state, confirmations)
    order["state"] = new_state
    return True

def find_utxos_for_label(label: str, min_conf: int) -> List[Dict[str,Any]]:
    # listunspent ignoriert Label-Filter parameter in manchen Cores; wir filtern hier
    utxos = rpc("listunspent", [min_conf, 9999999, [], True, {}])
    return [u for u in utxos if (u.get("label") or "") == label]

# ---- Routes ----
@app.get("/health")
def health():
    return {"ok": True}

@app.post("/orders", response_model=CreateOrderRes, dependencies=[Depends(require_api_key)])
def create_order(body: CreateOrderReq):
    existing = db.get_order(body.order_id)
    if existing:
        addr = rpc("deriveaddresses", [existing["descriptor"], [existing["index"], existing["index"]]])[0]
        return CreateOrderRes(
            escrow_address=addr,
            descriptor=existing["descriptor"],
            watch_id=f"escrow_{body.order_id}_{existing['index']}"
        )

    desc = build_descriptor(body.buyer.xpub, body.seller.xpub, body.escrow.xpub, body.index)
    info = rpc("getdescriptorinfo", [desc])
    desc_ck = f"{desc}#{info['checksum']}"
    label = f"escrow:{body.order_id}"

    rpc("importdescriptors", [[{
        "desc": desc_ck,
        "timestamp": "now",
        "label": label,
        "internal": False,
        "active": False,
        "range": [body.index, body.index]
    }]])
    addr = rpc("deriveaddresses", [desc_ck, [body.index, body.index]])[0]

    db.upsert_order(body.order_id, desc_ck, body.index, body.min_conf, label)
    return CreateOrderRes(
        escrow_address=addr,
        descriptor=desc_ck,
        watch_id=f"escrow_{body.order_id}_{body.index}"
    )

@app.get("/orders/{order_id}/status", response_model=StatusRes, dependencies=[Depends(require_api_key)])
def order_status(order_id: str):
    meta = db.get_order(order_id)
    if not meta:
        return StatusRes(state="awaiting_deposit")
    utxos = find_utxos_for_label(meta["label"], 0)
    if not utxos:
        return StatusRes(state=meta["state"])

    # nimm größtes UTXO (oder summe, je nach Policy)
    u = sorted(utxos, key=lambda x: x.get("amount",0), reverse=True)[0]
    tx = rpc("gettransaction", [u["txid"]])
    confs = int(tx.get("confirmations", 0))
    db.update_funding(order_id, u["txid"], u["vout"], confs)
    state = meta["state"]
    if confs >= int(meta["min_conf"]):
        changed = advance_state(meta, "escrow_funded", confs)
        state = "escrow_funded"
        if changed:
            woo_callback({"order_id": order_id, "event":"escrow_funded", "txid": u["txid"], "confs": confs})
    res = StatusRes(
        funding={
            "txid": u["txid"], "vout": u["vout"],
            "value_sat": int(round(u["amount"] * 1e8)),
            "confirmations": confs
        },
        state=state
    )
    return res

@app.post("/psbt/build", response_model=PSBTRes, dependencies=[Depends(require_api_key)])
def psbt_build(body: PSBTBuildReq):
    meta = db.get_order(body.order_id)
    if not meta:
        raise HTTPException(404, "order not found")
    utxos = find_utxos_for_label(meta["label"], int(meta["min_conf"]))
    if not utxos:
        raise HTTPException(400, "no funded utxo")

    ins = [{"txid": u["txid"], "vout": u["vout"]} for u in utxos]
    outs_btc: List[Dict[str,float]] = [{addr: sats/1e8} for addr, sats in body.outputs.items()]
    psbt = rpc("createpsbt", [ins, outs_btc, 0, True])
    advance_state(meta, "signing")
    return PSBTRes(psbt=psbt)

@app.post("/psbt/merge", response_model=PSBTRes, dependencies=[Depends(require_api_key)])
def psbt_merge(body: MergeReq):
    if not body.partials:
        raise HTTPException(400, "no partials")
    merged_list = body.partials
    if body.order_id:
        existing = db.get_order(body.order_id)
        if not existing:
            raise HTTPException(404, "order not found")
        prev = db.get_partials(body.order_id)
        new_parts = [p for p in body.partials if p not in prev]
        merged_list = prev + new_parts
        db.save_partials(body.order_id, merged_list)
    merged = rpc("combinepsbt", [merged_list])
    return PSBTRes(psbt=merged)

@app.post("/psbt/decode", response_model=DecodeRes, dependencies=[Depends(require_api_key)])
def psbt_decode(body: DecodeReq):
    dec = rpc("decodepsbt", [body.psbt])
    inputs = dec.get("inputs", [])
    count = 0
    if inputs:
        sigs = inputs[0].get("partial_signatures") or {}
        count = len(sigs)
    return DecodeRes(sign_count=count)

@app.post("/psbt/finalize", dependencies=[Depends(require_api_key)])
def psbt_finalize(body: FinalizeReq):
    if body.order_id:
        meta = db.get_order(body.order_id)
        if not meta:
            raise HTTPException(404, "order not found")
    fin = rpc("finalizepsbt", [body.psbt])
    if not fin.get("complete"):
        raise HTTPException(400, "not enough signatures")
    if body.order_id:
        if body.state not in {"completed", "refunded", "dispute"}:
            raise HTTPException(400, "invalid final state")
        advance_state(meta, body.state)
    return {"hex": fin["hex"]}

@app.post("/tx/broadcast", dependencies=[Depends(require_api_key)])
def tx_broadcast(body: BroadcastReq):
    txid = rpc("sendrawtransaction", [body.hex])
    if body.order_id:
        meta = db.get_order(body.order_id)
        if meta:
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
    meta = db.get_order(body.order_id)
    if not meta or not meta.get("payout_txid"):
        raise HTTPException(404, "txid not found")
    res = rpc("bumpfee", [meta["payout_txid"], {"confTarget": body.target_conf}])
    new_txid = res.get("txid") if isinstance(res, dict) else res
    db.set_payout_txid(body.order_id, new_txid)
    return {"txid": new_txid}
