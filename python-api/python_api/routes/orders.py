from typing import Any, Dict, List

from fastapi import APIRouter, Depends, HTTPException

import db
from ..models import (
    CreateOrderReq,
    CreateOrderRes,
    StatusRes,
    PayoutQuoteReq,
    PayoutQuoteRes,
)
from ..rpc import rpc, build_descriptor, find_utxos_for_label
from ..config import require_api_key
from ..logging import order_id_var
from ..workers import advance_state, woo_callback

router = APIRouter()


@router.post("/orders", response_model=CreateOrderRes, dependencies=[Depends(require_api_key)])
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

    fee_est_sat = 0
    try:
        fee_res = rpc("estimatesmartfee", [3])
        feerate = fee_res.get("feerate")
        if feerate:
            fee_est_sat = int(round(feerate * 1e5 * 150))
    except Exception:
        fee_est_sat = 0

    rpc("importdescriptors", [[{
        "desc": desc_ck,
        "timestamp": "now",
        "label": label,
        "internal": False,
        "active": False,
        "range": [idx, idx]
    }]])
    addr = rpc("deriveaddresses", [desc_ck, [idx, idx]])[0]

    db.upsert_order(body.order_id, desc_ck, idx, body.min_conf, label, body.amount_sat, fee_est_sat)
    return CreateOrderRes(
        escrow_address=addr,
        descriptor=desc_ck,
        watch_id=f"escrow_{body.order_id}_{idx}"
    )


@router.get("/orders/{order_id}/status", response_model=StatusRes, dependencies=[Depends(require_api_key)])
def order_status(order_id: str):
    order_id_var.set(order_id)
    meta = db.get_order(order_id)
    if not meta:
        return StatusRes(state="awaiting_deposit")
    utxos = find_utxos_for_label(meta["label"], 0)
    if not utxos:
        return StatusRes(state=meta["state"], deadline_ts=meta.get("deadline_ts"), fee_est_sat=meta.get("fee_est_sat"))

    total_sat = 0
    funding_utxos: List[Dict[str, Any]] = []
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
    fee_est = int(meta.get("fee_est_sat") or 0)
    expected = int(meta.get("amount_sat") or 0) + fee_est
    shortfall = 0
    if expected > 0 and min_conf is not None and min_conf >= int(meta["min_conf"]):
        tolerance = int(expected * 0.005)
        if total_sat + tolerance >= expected:
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
            if total_sat < expected:
                shortfall = expected - total_sat
        else:
            shortfall = expected - total_sat

    funding = {
        "utxos": funding_utxos,
        "total_sat": total_sat,
        "confirmations": min_conf,
    }
    if shortfall > 0:
        funding["shortfall_sat"] = shortfall

    res = StatusRes(
        funding=funding,
        state=state,
        deadline_ts=meta.get("deadline_ts"),
        fee_est_sat=fee_est,
    )
    return res


@router.post("/orders/{order_id}/payout_quote", response_model=PayoutQuoteRes, dependencies=[Depends(require_api_key)])
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
    outs = {body.address: meta["amount_sat"] / 1e8}
    res = rpc("walletcreatefundedpsbt", [ins, outs, 0, opts])
    psbt = res.get("psbt")
    dec = rpc("decodepsbt", [psbt])
    vout0 = dec.get("tx", {}).get("vout", [{}])[0]
    payout_sat = int(round(vout0.get("value", 0) * 1e8))
    if payout_sat != meta["amount_sat"]:
        raise HTTPException(400, "payout mismatch")
    if res.get("changepos", -1) != -1:
        raise HTTPException(400, "unexpected change output")
    fee_sat = int(round(res.get("fee", 0) * 1e8))
    return PayoutQuoteRes(fee_sat=fee_sat)
