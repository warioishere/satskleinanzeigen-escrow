import os, json, hmac, hashlib, time
from typing import Dict, Any, List, Optional
from fastapi import FastAPI, HTTPException, Header, Depends
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
import requests
from dotenv import load_dotenv

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

# ---- FastAPI app ----
app = FastAPI(title="Escrow API (2-of-3 P2WSH, PSBT)")
app.add_middleware(
    CORSMiddleware,
    allow_origins=ALLOW_ORIGINS,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

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

class BroadcastReq(BaseModel):
    hex: str
    order_id: Optional[str] = None

# ---- Helpers ----
ORDERS: Dict[str, Dict[str, Any]] = {}  # in-memory (ersetzbar durch DB)

def build_descriptor(xpub_b: str, xpub_s: str, xpub_e: str, index: int) -> str:
    # sortedmulti sortiert intern – Reihenfolge ist egal; wir geben sie wie geliefert weiter
    # Für MVP leiten wir Childs über /0/index ab
    return f"wsh(sortedmulti(2,{xpub_b}/0/{index},{xpub_s}/0/{index},{xpub_e}/0/{index}))"

def woo_callback(payload: Dict[str, Any]):
    if not (WOO_CALLBACK_URL and WOO_HMAC_SECRET):
        return
    body = json.dumps(payload, separators=(",",":")).encode()
    ts   = str(int(time.time()))
    sig  = hmac.new(WOO_HMAC_SECRET.encode(), ts.encode() + body, hashlib.sha256).hexdigest()
    try:
        requests.post(WOO_CALLBACK_URL, data=body, headers={
            "content-type":"application/json",
            "x-weo-sign": sig,
            "x-weo-ts": ts
        }, timeout=10)
    except Exception:
        pass

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
    desc = build_descriptor(body.buyer.xpub, body.seller.xpub, body.escrow.xpub, body.index)
    info = rpc("getdescriptorinfo", [desc])
    desc_ck = f"{desc}#{info['checksum']}"
    label = f"escrow:{body.order_id}"

    # enges Importfenster: nur dieser Index
    rpc("importdescriptors", [[{
        "desc": desc_ck,
        "timestamp": "now",
        "label": label,
        "internal": False,
        "active": False,
        "range": [body.index, body.index]
    }]])
    addr = rpc("deriveaddresses", [desc_ck, [body.index, body.index]])[0]

    ORDERS[body.order_id] = {
        "descriptor": desc_ck,
        "index": body.index,
        "min_conf": body.min_conf,
        "label": label
    }
    return CreateOrderRes(escrow_address=addr, descriptor=desc_ck, watch_id=f"escrow_{body.order_id}_{body.index}")

@app.get("/orders/{order_id}/status", response_model=StatusRes, dependencies=[Depends(require_api_key)])
def order_status(order_id: str):
    meta = ORDERS.get(order_id)
    if not meta:
        return StatusRes(state="awaiting_deposit")
    utxos = find_utxos_for_label(meta["label"], 0)
    if not utxos:
        return StatusRes(state="awaiting_deposit")

    # nimm größtes UTXO (oder summe, je nach Policy)
    u = sorted(utxos, key=lambda x: x.get("amount",0), reverse=True)[0]
    tx = rpc("gettransaction", [u["txid"]])
    confs = int(tx.get("confirmations", 0))
    state = "escrow_funded" if confs >= int(meta["min_conf"]) else "awaiting_deposit"
    res = StatusRes(
        funding={
            "txid": u["txid"], "vout": u["vout"],
            "value_sat": int(round(u["amount"] * 1e8)),
            "confirmations": confs
        },
        state=state
    )
    if state == "escrow_funded":
        woo_callback({"order_id": order_id, "event":"escrow_funded", "txid": u["txid"], "confs": confs})
    return res

@app.post("/psbt/build", response_model=PSBTRes, dependencies=[Depends(require_api_key)])
def psbt_build(body: PSBTBuildReq):
    meta = ORDERS.get(body.order_id)
    if not meta:
        raise HTTPException(404, "order not found")
    utxos = find_utxos_for_label(meta["label"], int(meta["min_conf"]))
    if not utxos:
        raise HTTPException(400, "no funded utxo")

    ins = [{"txid": u["txid"], "vout": u["vout"]} for u in utxos]
    outs_btc: List[Dict[str,float]] = [{addr: sats/1e8} for addr, sats in body.outputs.items()]
    psbt = rpc("createpsbt", [ins, outs_btc, 0, True])
    return PSBTRes(psbt=psbt)

@app.post("/psbt/merge", response_model=PSBTRes, dependencies=[Depends(require_api_key)])
def psbt_merge(body: MergeReq):
    if not body.partials:
        raise HTTPException(400, "no partials")
    merged = rpc("combinepsbt", [body.partials])
    return PSBTRes(psbt=merged)

@app.post("/psbt/finalize", dependencies=[Depends(require_api_key)])
def psbt_finalize(body: FinalizeReq):
    fin = rpc("finalizepsbt", [body.psbt])
    if not fin.get("complete"):
        raise HTTPException(400, "not enough signatures")
    return {"hex": fin["hex"]}

@app.post("/tx/broadcast", dependencies=[Depends(require_api_key)])
def tx_broadcast(body: BroadcastReq):
    txid = rpc("sendrawtransaction", [body.hex])
    if body.order_id:
        woo_callback({"order_id": body.order_id, "event": "settled", "txid": txid})
    return {"txid": txid}
