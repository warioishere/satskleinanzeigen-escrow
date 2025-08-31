import os, sqlite3, time, json
from typing import Any, Dict, List, Optional

DB_PATH = os.getenv("ORDERS_DB", "orders.sqlite")


def get_conn():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn


def init_db():
    conn = get_conn()
    cur = conn.cursor()
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS orders (
            order_id TEXT PRIMARY KEY,
            descriptor TEXT,
            index INTEGER,
            min_conf INTEGER,
            label TEXT,
            created_at INTEGER,
            state TEXT,
            funding_txid TEXT,
            vout INTEGER,
            confirmations INTEGER,
            partials TEXT,
            last_webhook_ts INTEGER
        )
        """
    )
    conn.commit()
    conn.close()


def upsert_order(order_id: str, descriptor: str, index: int, min_conf: int, label: str):
    conn = get_conn()
    now = int(time.time())
    conn.execute(
        """
        INSERT INTO orders(order_id, descriptor, index, min_conf, label, created_at, state)
        VALUES(?,?,?,?,?,?,?)
        ON CONFLICT(order_id) DO UPDATE SET
            descriptor=excluded.descriptor,
            index=excluded.index,
            min_conf=excluded.min_conf,
            label=excluded.label
        """,
        (order_id, descriptor, index, min_conf, label, now, "awaiting_deposit"),
    )
    conn.commit()
    conn.close()


def get_order(order_id: str) -> Optional[Dict[str, Any]]:
    conn = get_conn()
    cur = conn.execute("SELECT * FROM orders WHERE order_id=?", (order_id,))
    row = cur.fetchone()
    conn.close()
    return dict(row) if row else None


def get_partials(order_id: str) -> List[str]:
    conn = get_conn()
    cur = conn.execute("SELECT partials FROM orders WHERE order_id=?", (order_id,))
    row = cur.fetchone()
    conn.close()
    if not row or not row["partials"]:
        return []
    try:
        return json.loads(row["partials"])
    except Exception:
        return []


def update_state(order_id: str, state: str, confirmations: Optional[int] = None):
    conn = get_conn()
    now = int(time.time())
    if confirmations is not None:
        conn.execute(
            "UPDATE orders SET state=?, confirmations=?, created_at=? WHERE order_id=?",
            (state, confirmations, now, order_id),
        )
    else:
        conn.execute(
            "UPDATE orders SET state=?, created_at=? WHERE order_id=?",
            (state, now, order_id),
        )
    conn.commit()
    conn.close()


def save_partials(order_id: str, partials: List[str]):
    conn = get_conn()
    conn.execute(
        "UPDATE orders SET partials=? WHERE order_id=?",
        (json.dumps(partials), order_id),
    )
    conn.commit()
    conn.close()


def update_funding(order_id: str, txid: str, vout: int, confirmations: int):
    conn = get_conn()
    conn.execute(
        "UPDATE orders SET funding_txid=?, vout=?, confirmations=? WHERE order_id=?",
        (txid, vout, confirmations, order_id),
    )
    conn.commit()
    conn.close()


def set_last_webhook_ts(order_id: str, ts: int):
    conn = get_conn()
    conn.execute(
        "UPDATE orders SET last_webhook_ts=? WHERE order_id=?",
        (ts, order_id),
    )
    conn.commit()
    conn.close()
