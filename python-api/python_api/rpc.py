import time
from typing import Any, List, Dict

import time
import requests
from fastapi import HTTPException

from .config import (
    BTC_CORE_URL,
    BTC_CORE_USER,
    BTC_CORE_PASS,
    BTC_CORE_WALLET,
)
from .logging import log, req_id_var, order_id_var, actor_var
from .metrics import RPC_HIST


def rpc(method: str, params: List[Any] = None) -> Any:
    bound = log.bind(
        request_id=req_id_var.get(),
        order_id=order_id_var.get(),
        actor=actor_var.get(),
        rpc_method=method,
    )
    url = BTC_CORE_URL.rstrip("/") + f"/wallet/{BTC_CORE_WALLET}"
    payload = {"jsonrpc": "1.0", "id": "escrow", "method": method, "params": params or []}
    auth = (BTC_CORE_USER, BTC_CORE_PASS) if BTC_CORE_USER or BTC_CORE_PASS else None
    start = time.time()
    bound.info("rpc_start", params=params)
    r = None
    try:
        r = requests.post(url, json=payload, auth=auth, timeout=25)
        j = r.json()
    except Exception as e:
        bound.error("rpc_error", error=str(e), duration=time.time() - start)
        raise HTTPException(status_code=502, detail=f"Core RPC bad response ({getattr(r, 'status_code', 'n/a')})")
    if j.get("error"):
        bound.error("rpc_error", error=j["error"], duration=time.time() - start)
        raise HTTPException(status_code=500, detail=j["error"]["message"])
    duration = time.time() - start
    bound.info("rpc_success", duration=duration)
    RPC_HIST.labels(method=method).observe(duration)
    return j["result"]


def build_descriptor(xpub_b: str, xpub_s: str, xpub_e: str, index: int) -> str:
    return f"wsh(multi(2,{xpub_b}/0/{index}/*,{xpub_s}/0/{index}/*,{xpub_e}/0/{index}/*))"


def find_utxos_for_label(label: str, min_conf: int) -> List[Dict[str, Any]]:
    utxos = rpc("listunspent", [min_conf, 9999999, [], True, {}])
    return [u for u in utxos if (u.get("label") or "") == label]
