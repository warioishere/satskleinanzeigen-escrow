import os, sys, tempfile, types
from fastapi.testclient import TestClient
import importlib


def create_client(monkeypatch):
    fd, db_path = tempfile.mkstemp(); os.close(fd)
    monkeypatch.setenv("ORDERS_DB", db_path)
    monkeypatch.setenv("ALLOW_ORIGINS", "http://test")
    monkeypatch.setenv("API_KEYS", "testkey")
    stub = types.ModuleType("db")
    import sqlite3, json, time
    def init_db():
        conn = sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; cur = conn.cursor()
        cur.execute("CREATE TABLE orders(order_id TEXT PRIMARY KEY, descriptor TEXT, idx INTEGER, min_conf INTEGER, label TEXT, amount_sat INTEGER, created_at INTEGER, state TEXT, funding_txid TEXT, vout INTEGER, confirmations INTEGER, partials TEXT, outputs TEXT, output_type TEXT, last_webhook_ts INTEGER, payout_txid TEXT, deadline_ts INTEGER)")
        conn.commit(); conn.close()
    def next_index():
        conn = sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; cur = conn.execute("SELECT MAX(idx) FROM orders"); row = cur.fetchone(); conn.close(); return (row[0]+1) if row and row[0] is not None else 0
    def upsert_order(order_id, descriptor, index, min_conf, label, amount_sat):
        conn = sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; now = int(time.time()); conn.execute("INSERT OR REPLACE INTO orders(order_id, descriptor, idx, min_conf, label, amount_sat, created_at, state) VALUES(?,?,?,?,?,?,?,?)", (order_id, descriptor, index, min_conf, label, amount_sat, now, 'awaiting_deposit')); conn.commit(); conn.close()
    def get_order(order_id):
        conn = sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; cur = conn.execute("SELECT * FROM orders WHERE order_id=?", (order_id,)); row = cur.fetchone(); conn.close(); return dict(row) if row else None
    def update_state(order_id, state, conf=None, deadline=None):
        conn = sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; cur_fields=['state=?']; params=[state]
        if conf is not None: cur_fields.append('confirmations=?'); params.append(conf)
        if deadline is not None: cur_fields.append('deadline_ts=?'); params.append(deadline)
        params.append(order_id); conn.execute(f"UPDATE orders SET {', '.join(cur_fields)} WHERE order_id=?", params); conn.commit(); conn.close()
    def set_outputs(order_id, outputs, out_type):
        conn = sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; conn.execute("UPDATE orders SET outputs=?, output_type=? WHERE order_id=?", (json.dumps(outputs), out_type, order_id)); conn.commit(); conn.close()
    def get_outputs(order_id):
        conn = sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; cur = conn.execute("SELECT outputs FROM orders WHERE order_id=?", (order_id,)); row=cur.fetchone(); conn.close(); import json as js; return js.loads(row[0]) if row and row[0] else {}
    def save_partials(order_id, partials):
        conn=sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; import json as js; conn.execute("UPDATE orders SET partials=? WHERE order_id=?", (js.dumps(partials), order_id)); conn.commit(); conn.close()
    def get_partials(order_id):
        conn=sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; cur=conn.execute("SELECT partials FROM orders WHERE order_id=?", (order_id,)); row=cur.fetchone(); conn.close(); import json as js; return js.loads(row[0]) if row and row[0] else []
    def set_payout_txid(order_id, txid):
        conn=sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; conn.execute("UPDATE orders SET payout_txid=? WHERE order_id=?", (txid, order_id)); conn.commit(); conn.close()
    def update_funding(order_id, txid, vout, conf):
        conn=sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; conn.execute("UPDATE orders SET funding_txid=?, vout=?, confirmations=? WHERE order_id=?", (txid, vout, conf, order_id)); conn.commit(); conn.close()
    stub.init_db=init_db; stub.next_index=next_index; stub.upsert_order=upsert_order
    stub.get_order=get_order; stub.update_state=update_state; stub.set_outputs=set_outputs
    stub.get_outputs=get_outputs; stub.save_partials=save_partials; stub.get_partials=get_partials
    stub.set_payout_txid=set_payout_txid; stub.update_funding=update_funding
    stub.count_pending_signatures=lambda:0
    stub.list_orders_by_states=lambda states: []
    sys.modules['db']=stub
    sys.modules.pop('api', None)
    from prometheus_client import REGISTRY
    for c in list(REGISTRY._collector_to_names.keys()):
        try:
            REGISTRY.unregister(c)
        except Exception:
            pass
    import api
    return TestClient(api.app)


def stub_rpc(method, params=None):
    if method == 'getdescriptorinfo':
        return {'checksum':'abcd'}
    if method == 'importdescriptors':
        return [{'success':True}]
    if method == 'deriveaddresses':
        return ['tb1qaddr']
    if method == 'createpsbt':
        return 'psbt1'
    if method == 'walletcreatefundedpsbt':
        return {'psbt':'psbtR','changepos':-1}
    if method == 'decodepsbt':
        psbt=params[0]
        if psbt=='merged':
            return {'tx':{'vin':[{'txid':'tx1','vout':0,'sequence':0xfffffffd}], 'vout':[{'value':0.00055,'scriptPubKey':{'addresses':['tb1qseller111']}}]}, 'inputs':[{'partial_signatures':{'a':'s','b':'s'}}]}
        elif psbt=='psbtR':
            return {'tx':{'vout':[{'value':0.00055,'scriptPubKey':{'addresses':['tb1qrefunded0']}}]}}
        else:
            return {'inputs':[{'partial_signatures':{'a':'s'}}], 'tx':{'vout':[{'value':0.00055,'scriptPubKey':{'addresses':['tb1qseller111']}}]}}
    if method == 'analyzepsbt':
        return {'fee':0.00005}
    if method == 'combinepsbt':
        return 'merged'
    if method == 'gettransaction':
        return {'confirmations':2,'details':[{'vout':0,'label':'escrow:order1'}]}
    if method == 'gettxout':
        return {'value':0.0006}
    if method == 'finalizepsbt':
        return {'hex':'deadbeef','complete':True}
    if method == 'sendrawtransaction':
        return 'txid123'
    if method == 'bumpfee':
        return {'txid':'bumped'}
    return {}


def stub_utxos(label, min_conf):
    return [{'txid':'tx1','vout':0,'amount':0.0006}]


def test_full_payout_flow(monkeypatch):
    client=create_client(monkeypatch)
    import api
    monkeypatch.setattr(api, 'rpc', stub_rpc)
    monkeypatch.setattr(api, 'find_utxos_for_label', stub_utxos)
    headers={'x-api-key':'testkey'}
    body={'order_id':'order1','buyer':{'xpub':'X'},'seller':{'xpub':'Y'},'escrow':{'xpub':'Z'},'min_conf':2,'amount_sat':60000}
    r=client.post('/orders', json=body, headers=headers)
    assert r.status_code==200
    r=client.get('/orders/order1/status', headers=headers)
    assert r.json()['state']=='escrow_funded'
    r=client.post('/psbt/build', json={'order_id':'order1','outputs':{'tb1qseller111':55000}}, headers=headers)
    assert r.status_code==200, r.text
    assert r.json()['psbt']=='psbt1'
    r=client.post('/psbt/merge', json={'order_id':'order1','partials':['cDE=','cDI=']}, headers=headers)
    assert r.status_code==200, r.text
    assert r.json()['psbt']=='merged'
    r=client.post('/psbt/decode', json={'psbt':'merged'}, headers=headers)
    assert r.status_code==200, r.text
    dec=r.json()
    assert dec['sign_count']==2
    assert dec['outputs']['tb1qseller111']==55000
    assert dec['fee_sat']==5000
    r=client.post('/psbt/finalize', json={'order_id':'order1','psbt':'merged','state':'completed'}, headers=headers)
    assert r.status_code==200, r.text
    assert r.json()['hex']=='deadbeef'
    r=client.post('/tx/broadcast', json={'order_id':'order1','hex':'deadbeef','state':'completed'}, headers=headers)
    assert r.status_code==200, r.text
    assert r.json()['txid']=='txid123'
    r=client.post('/tx/bumpfee', json={'order_id':'order1','target_conf':2}, headers=headers)
    assert r.status_code==200, r.text
    assert r.json()['txid']=='bumped'


def test_refund_psbt(monkeypatch):
    client=create_client(monkeypatch)
    import api
    monkeypatch.setattr(api, 'rpc', stub_rpc)
    monkeypatch.setattr(api, 'find_utxos_for_label', stub_utxos)
    headers={'x-api-key':'testkey'}
    body={'order_id':'order2','buyer':{'xpub':'X'},'seller':{'xpub':'Y'},'escrow':{'xpub':'Z'},'min_conf':2,'amount_sat':60000}
    r=client.post('/orders', json=body, headers=headers)
    assert r.status_code==200
    r=client.get('/orders/order2/status', headers=headers)
    assert r.json()['state']=='escrow_funded'
    r=client.post('/psbt/build_refund', json={'order_id':'order2','address':'tb1qrefunded0'}, headers=headers)
    assert r.status_code==200, r.text
    assert r.json()['psbt']=='psbtR'
