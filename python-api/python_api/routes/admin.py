from typing import Dict

from fastapi import APIRouter, Depends, HTTPException, Response
from fastapi.responses import PlainTextResponse
from prometheus_client import generate_latest, CONTENT_TYPE_LATEST

import db
from ..rpc import rpc
from ..models import BroadcastReq, BumpFeeReq, PSBTRes, FinalizeReq
from ..config import require_api_key
from ..metrics import WEBHOOK_QUEUE_SIZE, BROADCAST_FAIL
from ..logging import order_id_var, log
from ..workers import _webhook_q, advance_state, woo_callback

router = APIRouter()


@router.get("/live")
def live():
    return {"ok": True}


@router.get("/health", dependencies=[Depends(require_api_key)])
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


@router.get("/metrics", dependencies=[Depends(require_api_key)])
def metrics():
    return PlainTextResponse(generate_latest(), media_type=CONTENT_TYPE_LATEST)


@router.post("/tx/broadcast", dependencies=[Depends(require_api_key)])
def tx_broadcast(body: BroadcastReq):
    meta = None
    if getattr(body, 'order_id', None):
        order_id_var.set(body.order_id)
        meta = db.get_order(body.order_id)
        if not meta:
            raise HTTPException(404, "order not found")
    try:
        txid = rpc("sendrawtransaction", [body.hex])
    except HTTPException as e:
        BROADCAST_FAIL.inc()
        log.error("broadcast_fail", error=e.detail, order_id=getattr(body, 'order_id', None))
        raise
    if getattr(body, 'order_id', None) and meta:
        db.set_payout_txid(body.order_id, txid)
        if body.state not in {"completed", "refunded", "dispute"}:
            raise HTTPException(400, "invalid final state")
        advance_state(meta, body.state)
        if not meta.get("last_webhook_ts"):
            event = "settled" if body.state == "completed" else body.state
            woo_callback({"order_id": body.order_id, "event": event, "txid": txid})
    return {"txid": txid}


@router.post("/tx/bumpfee", response_model=PSBTRes, dependencies=[Depends(require_api_key)])
def tx_bumpfee(body: BumpFeeReq):
    order_id_var.set(body.order_id)
    meta = db.get_order(body.order_id)
    if not meta or not meta.get("payout_txid"):
        raise HTTPException(404, "txid not found")
    res = rpc("bumpfee", [meta["payout_txid"], {"confTarget": body.target_conf, "psbt": True}])
    psbt = res.get("psbt") if isinstance(res, dict) else None
    if not psbt:
        raise HTTPException(500, "bumpfee failed")
    db.start_rbf(body.order_id, psbt)
    return PSBTRes(psbt=psbt)




@router.post("/tx/bumpfee/finalize", dependencies=[Depends(require_api_key)])
def tx_bumpfee_finalize(body: FinalizeReq):
    order_id_var.set(body.order_id)
    meta = db.get_order(body.order_id)
    if not meta or meta.get("state") != "rbf_signing":
        raise HTTPException(404, "rbf not in progress")
    base_psbt = db.get_rbf_psbt(body.order_id)
    if not base_psbt:
        raise HTTPException(404, "rbf psbt not found")

    # ensure inputs and outputs match stored PSBT and allowed outputs
    base_dec = rpc("decodepsbt", [base_psbt])
    new_dec = rpc("decodepsbt", [body.psbt])
    base_ins = base_dec.get("tx", {}).get("vin", [])
    new_ins = new_dec.get("tx", {}).get("vin", [])
    if len(base_ins) != len(new_ins):
        raise HTTPException(400, "inputs mismatch")
    for a, b in zip(base_ins, new_ins):
        if a.get("txid") != b.get("txid") or a.get("vout") != b.get("vout"):
            raise HTTPException(400, "inputs mismatch")
        if b.get("sequence", 0xffffffff) >= 0xfffffffe:
            raise HTTPException(400, "RBF disabled")

    allowed = db.get_outputs(body.order_id)
    new_vouts = new_dec.get("tx", {}).get("vout", [])
    decoded: Dict[str, int] = {}
    for o in new_vouts:
        addrs = o.get("scriptPubKey", {}).get("addresses", [])
        if len(addrs) != 1:
            raise HTTPException(400, "unexpected output")
        decoded[addrs[0]] = int(round(o.get("value", 0) * 1e8))
    if decoded != allowed:
        raise HTTPException(400, "outputs mismatch")

    fin = rpc("finalizepsbt", [body.psbt])
    if not fin.get("complete"):
        raise HTTPException(400, "not enough signatures")
    txid = rpc("sendrawtransaction", [fin["hex"]])
    db.set_payout_txid(body.order_id, txid)
    db.clear_rbf(body.order_id)
    return {"txid": txid}
