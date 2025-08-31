from typing import Dict, List

from fastapi import APIRouter, Depends, HTTPException

import db
from ..models import (
    PSBTBuildReq,
    PSBTRefundReq,
    PSBTRes,
    MergeReq,
    DecodeReq,
    DecodeRes,
    FinalizeReq,
)
from ..rpc import rpc, find_utxos_for_label
from ..config import require_api_key, order_id_var, log
from .. import advance_state, update_pending_gauge

router = APIRouter()


@router.post("/psbt/build", response_model=PSBTRes, dependencies=[Depends(require_api_key)])
def psbt_build(body: PSBTBuildReq):
    order_id_var.set(body.order_id)
    meta = db.get_order(body.order_id)
    if not meta:
        raise HTTPException(404, "order not found")
    utxos = find_utxos_for_label(meta["label"], int(meta["min_conf"]))
    if not utxos:
        raise HTTPException(400, "no funded utxo")

    ins = [{"txid": u["txid"], "vout": u["vout"]} for u in utxos]
    required = sum(body.outputs.values())
    in_total = sum(int(round(u.get("amount", 0) * 1e8)) for u in utxos)
    if in_total < required:
        raise HTTPException(400, "insufficient funds")
    outs_btc = {addr: sats / 1e8 for addr, sats in body.outputs.items()}
    opts = {
        "includeWatching": True,
        "replaceable": body.rbf,
        "conf_target": body.target_conf,
        "subtractFeeFromOutputs": [0],
    }
    res = rpc("walletcreatefundedpsbt", [ins, outs_btc, 0, opts])
    if res.get("changepos", -1) != -1:
        raise HTTPException(400, "unexpected change output")
    psbt = res.get("psbt")
    dec = rpc("decodepsbt", [psbt])
    outs = dec.get("tx", {}).get("vout", [])
    if len(outs) != len(body.outputs):
        raise HTTPException(400, "unexpected outputs")
    out_map: Dict[str, int] = {}
    for o in outs:
        addrs = o.get("scriptPubKey", {}).get("addresses", [])
        if len(addrs) != 1 or addrs[0] not in body.outputs:
            raise HTTPException(400, "outputs mismatch")
        val_sat = int(round(o.get("value", 0) * 1e8))
        if addrs[0] in body.outputs and val_sat != meta["amount_sat"]:
            raise HTTPException(400, "payout mismatch")
        out_map[addrs[0]] = val_sat
    db.set_outputs(body.order_id, out_map, "payout")
    advance_state(meta, "signing")
    return PSBTRes(psbt=psbt)


@router.post("/psbt/build_refund", response_model=PSBTRes, dependencies=[Depends(require_api_key)])
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


@router.post("/psbt/merge", response_model=PSBTRes, dependencies=[Depends(require_api_key)])
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


@router.post("/psbt/decode", response_model=DecodeRes, dependencies=[Depends(require_api_key)])
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
    count = sum(len(inp.get("partial_signatures") or {}) for inp in inputs)
    return DecodeRes(sign_count=count, outputs=outs, fee_sat=fee_sat)


@router.post("/psbt/finalize", dependencies=[Depends(require_api_key)])
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
