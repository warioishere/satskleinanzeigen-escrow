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
            amount_sat INTEGER,
            fee_est_sat INTEGER,
            created_at INTEGER,
            state TEXT,
            funding_txid TEXT,
            vout INTEGER,
            confirmations INTEGER,
            partials TEXT,
            rbf_partials TEXT,
            outputs TEXT,
            output_type TEXT,
            last_webhook_ts INTEGER,
            payout_txid TEXT,
            deadline_ts INTEGER,
            rbf_psbt TEXT,
            rbf_state TEXT
        )
        """,
    )
    cols = [r[1] for r in cur.execute("PRAGMA table_info(orders)")]
    if "outputs" not in cols:
        cur.execute("ALTER TABLE orders ADD COLUMN outputs TEXT")
    if "output_type" not in cols:
        cur.execute("ALTER TABLE orders ADD COLUMN output_type TEXT")
    if "payout_txid" not in cols:
        cur.execute("ALTER TABLE orders ADD COLUMN payout_txid TEXT")
    if "deadline_ts" not in cols:
        cur.execute("ALTER TABLE orders ADD COLUMN deadline_ts INTEGER")
    if "amount_sat" not in cols:
        cur.execute("ALTER TABLE orders ADD COLUMN amount_sat INTEGER")
    if "fee_est_sat" not in cols:
        cur.execute("ALTER TABLE orders ADD COLUMN fee_est_sat INTEGER")
    if "rbf_psbt" not in cols:
        cur.execute("ALTER TABLE orders ADD COLUMN rbf_psbt TEXT")
    if "rbf_partials" not in cols:
        cur.execute("ALTER TABLE orders ADD COLUMN rbf_partials TEXT")
    if "rbf_state" not in cols:
        cur.execute("ALTER TABLE orders ADD COLUMN rbf_state TEXT")
    conn.commit()
    conn.close()


def next_index() -> int:
    conn = get_conn()
    cur = conn.execute("SELECT MAX(index) FROM orders")
    row = cur.fetchone()
    conn.close()
    return (row[0] + 1) if row and row[0] is not None else 0


def upsert_order(order_id: str, descriptor: str, index: int, min_conf: int, label: str, amount_sat: int, fee_est_sat: int):
    conn = get_conn()
    now = int(time.time())
    conn.execute(
        """
        INSERT INTO orders(order_id, descriptor, index, min_conf, label, amount_sat, fee_est_sat, created_at, state)
        VALUES(?,?,?,?,?,?,?,?,?)
        ON CONFLICT(order_id) DO UPDATE SET
            descriptor=excluded.descriptor,
            index=excluded.index,
            min_conf=excluded.min_conf,
            label=excluded.label,
            amount_sat=excluded.amount_sat,
            fee_est_sat=excluded.fee_est_sat
        """,
        (order_id, descriptor, index, min_conf, label, amount_sat, fee_est_sat, now, "awaiting_deposit"),
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


def get_rbf_partials(order_id: str) -> List[str]:
    conn = get_conn()
    cur = conn.execute("SELECT rbf_partials FROM orders WHERE order_id=?", (order_id,))
    row = cur.fetchone()
    conn.close()
    if not row or not row["rbf_partials"]:
        return []
    try:
        return json.loads(row["rbf_partials"])
    except Exception:
        return []
    try:
        return json.loads(row["partials"])
    except Exception:
        return []


def update_state(
    order_id: str,
    state: str,
    confirmations: Optional[int] = None,
    deadline: Optional[int] = None,
):
    conn = get_conn()
    now = int(time.time())
    fields = ["state=?", "created_at=?"]
    params: List[Any] = [state, now]
    if confirmations is not None:
        fields.append("confirmations=?")
        params.append(confirmations)
    if deadline is not None:
        fields.append("deadline_ts=?")
        params.append(deadline)
    sql = f"UPDATE orders SET {', '.join(fields)} WHERE order_id=?"
    params.append(order_id)
    conn.execute(sql, params)
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


def save_rbf_partials(order_id: str, partials: List[str]):
    conn = get_conn()
    conn.execute(
        "UPDATE orders SET rbf_partials=? WHERE order_id=?",
        (json.dumps(partials), order_id),
    )
    conn.commit()
    conn.close()


def set_outputs(order_id: str, outputs: Dict[str, int], output_type: str):
    conn = get_conn()
    conn.execute(
        "UPDATE orders SET outputs=?, output_type=? WHERE order_id=?",
        (json.dumps(outputs), output_type, order_id),
    )
    conn.commit()
    conn.close()


def get_outputs(order_id: str) -> Dict[str, int]:
    conn = get_conn()
    cur = conn.execute("SELECT outputs FROM orders WHERE order_id=?", (order_id,))
    row = cur.fetchone()
    conn.close()
    if not row or not row["outputs"]:
        return {}
    try:
        return json.loads(row["outputs"])
    except Exception:
        return {}


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


def set_payout_txid(order_id: str, txid: str):
    conn = get_conn()
    conn.execute(
        "UPDATE orders SET payout_txid=? WHERE order_id=?",
        (txid, order_id),
    )
    conn.commit()
    conn.close()


def start_rbf(order_id: str, psbt: str):
    conn = get_conn()
    cur = conn.execute("SELECT state FROM orders WHERE order_id=?", (order_id,))
    row = cur.fetchone()
    prev_state = row["state"] if row else None
    conn.execute(
        "UPDATE orders SET rbf_psbt=?, rbf_partials=NULL, partials=NULL, rbf_state=?, state='rbf_signing' WHERE order_id=?",
        (psbt, prev_state, order_id),
    )
    conn.commit()
    conn.close()


def get_rbf_psbt(order_id: str) -> Optional[str]:
    conn = get_conn()
    cur = conn.execute("SELECT rbf_psbt FROM orders WHERE order_id=?", (order_id,))
    row = cur.fetchone()
    conn.close()
    return row["rbf_psbt"] if row and row["rbf_psbt"] else None


def clear_rbf(order_id: str):
    conn = get_conn()
    cur = conn.execute("SELECT rbf_state FROM orders WHERE order_id=?", (order_id,))
    row = cur.fetchone()
    next_state = row["rbf_state"] if row else None
    conn.execute(
        "UPDATE orders SET rbf_psbt=NULL, rbf_partials=NULL, rbf_state=NULL, state=? WHERE order_id=?",
        (next_state, order_id),
    )
    conn.commit()
    conn.close()


def count_pending_signatures() -> int:
    conn = get_conn()
    cur = conn.execute("SELECT partials FROM orders WHERE state='signing'")
    rows = cur.fetchall()
    conn.close()
    pending = 0
    for r in rows:
        try:
            parts = json.loads(r["partials"]) if r["partials"] else []
        except Exception:
            parts = []
        if len(parts) < 2:
            pending += 2 - len(parts)
    return pending


def list_orders_by_states(states: List[str]) -> List[Dict[str, Any]]:
    conn = get_conn()
    qmarks = ",".join(["?"] * len(states))
    cur = conn.execute(
        f"SELECT * FROM orders WHERE state IN ({qmarks})",
        states,
    )
    rows = cur.fetchall()
    conn.close()
    return [dict(r) for r in rows]

