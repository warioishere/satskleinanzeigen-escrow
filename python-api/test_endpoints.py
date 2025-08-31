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
        cur.execute("CREATE TABLE orders(order_id TEXT PRIMARY KEY, descriptor TEXT, idx INTEGER, min_conf INTEGER, label TEXT, amount_sat INTEGER, fee_est_sat INTEGER, created_at INTEGER, state TEXT, funding_txid TEXT, vout INTEGER, confirmations INTEGER, partials TEXT, rbf_partials TEXT, outputs TEXT, output_type TEXT, last_webhook_ts INTEGER, payout_txid TEXT, deadline_ts INTEGER, rbf_psbt TEXT, rbf_state TEXT)")
        conn.commit(); conn.close()
    def next_index():
        conn = sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; cur = conn.execute("SELECT MAX(idx) FROM orders"); row = cur.fetchone(); conn.close(); return (row[0]+1) if row and row[0] is not None else 0
    def upsert_order(order_id, descriptor, index, min_conf, label, amount_sat, fee_est_sat):
        conn = sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; now = int(time.time()); conn.execute("INSERT OR REPLACE INTO orders(order_id, descriptor, idx, min_conf, label, amount_sat, fee_est_sat, created_at, state) VALUES(?,?,?,?,?,?,?,?,?)", (order_id, descriptor, index, min_conf, label, amount_sat, fee_est_sat, now, 'awaiting_deposit')); conn.commit(); conn.close()
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
    def save_rbf_partials(order_id, partials):
        conn=sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; import json as js; conn.execute("UPDATE orders SET rbf_partials=? WHERE order_id=?", (js.dumps(partials), order_id)); conn.commit(); conn.close()
    def get_rbf_partials(order_id):
        conn=sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; cur=conn.execute("SELECT rbf_partials FROM orders WHERE order_id=?", (order_id,)); row=cur.fetchone(); conn.close(); import json as js; return js.loads(row[0]) if row and row[0] else []
    def set_payout_txid(order_id, txid):
        conn=sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; conn.execute("UPDATE orders SET payout_txid=? WHERE order_id=?", (txid, order_id)); conn.commit(); conn.close()
    def update_funding(order_id, txid, vout, conf):
        conn=sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; conn.execute("UPDATE orders SET funding_txid=?, vout=?, confirmations=? WHERE order_id=?", (txid, vout, conf, order_id)); conn.commit(); conn.close()
    def start_rbf(order_id, psbt):
        conn=sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; cur=conn.execute("SELECT state FROM orders WHERE order_id=?", (order_id,)); row=cur.fetchone(); prev=row[0] if row else None; conn.execute("UPDATE orders SET rbf_psbt=?, rbf_partials=NULL, partials=NULL, rbf_state=?, state='rbf_signing' WHERE order_id=?", (psbt, prev, order_id)); conn.commit(); conn.close()
    def get_rbf_psbt(order_id):
        conn=sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; cur=conn.execute("SELECT rbf_psbt FROM orders WHERE order_id=?", (order_id,)); row=cur.fetchone(); conn.close(); return row[0] if row and row[0] else None
    def clear_rbf(order_id):
        conn=sqlite3.connect(db_path); conn.row_factory=sqlite3.Row; cur=conn.execute("SELECT rbf_state FROM orders WHERE order_id=?", (order_id,)); row=cur.fetchone(); nxt=row[0] if row else None; conn.execute("UPDATE orders SET rbf_psbt=NULL, rbf_partials=NULL, rbf_state=NULL, state=? WHERE order_id=?", (nxt, order_id)); conn.commit(); conn.close()
    stub.init_db=init_db; stub.next_index=next_index; stub.upsert_order=upsert_order
    stub.get_order=get_order; stub.update_state=update_state; stub.set_outputs=set_outputs
    stub.get_outputs=get_outputs; stub.save_partials=save_partials; stub.get_partials=get_partials
    stub.save_rbf_partials=save_rbf_partials; stub.get_rbf_partials=get_rbf_partials
    stub.set_payout_txid=set_payout_txid; stub.update_funding=update_funding
    stub.start_rbf=start_rbf; stub.get_rbf_psbt=get_rbf_psbt; stub.clear_rbf=clear_rbf
    stub.count_pending_signatures=lambda:0
    stub.list_orders_by_states=lambda states: []
    sys.modules['db']=stub
    for m in [k for k in list(sys.modules.keys()) if k.startswith('python_api')]:
        sys.modules.pop(m, None)
    from prometheus_client import REGISTRY
    for c in list(REGISTRY._collector_to_names.keys()):
        try:
            REGISTRY.unregister(c)
        except Exception:
            pass
    import python_api
    return TestClient(python_api.app)


def stub_rpc(method, params=None):
    if method == 'getdescriptorinfo':
        return {'checksum':'abcd'}
    if method == 'importdescriptors':
        return [{'success':True}]
    if method == 'deriveaddresses':
        return ['tb1qaddr']
    if method == 'walletcreatefundedpsbt':
        outs = params[1]
        if isinstance(outs, list):
            raise ValueError('outputs must be object')
        if outs == {'tb1qrefunded0': 0}:
            return {'psbt': 'psbtR', 'changepos': -1, 'fee': 0.000065}
        if outs == {'tb1qseller111': 0.0006}:
            return {'psbt': 'psbtQ', 'changepos': -1, 'fee': 0.000065}
        return {'psbt': 'psbtP', 'changepos': -1, 'fee': 0.000065}
    if method == 'decodepsbt':
        psbt = params[0]
        if not isinstance(psbt, str):
            raise ValueError('psbt must be str')
        if psbt == 'merged':
            return {
                'tx': {'vin': [{'txid': 'tx1', 'vout': 0, 'sequence': 0xfffffffd}], 'vout': [{'value': 0.0006, 'scriptPubKey': {'addresses': ['tb1qseller111']}}]},
                'inputs': [{'partial_signatures': {'a': 's', 'b': 's'}}]
            }
        elif psbt == 'psbtR':
            return {'tx': {'vout': [{'value': 0.00055, 'scriptPubKey': {'addresses': ['tb1qrefunded0']}}]}}
        elif psbt == 'rbfBase':
            return {
                'tx': {
                    'vin': [
                        {'txid': 'tx1', 'vout': 0, 'sequence': 0xfffffffd},
                        {'txid': 'tx2', 'vout': 1, 'sequence': 0xfffffffd},
                    ],
                    'vout': [
                        {'value': 0.0006, 'scriptPubKey': {'addresses': ['tb1qseller111']}},
                        {'value': 0.00004, 'scriptPubKey': {'addresses': ['tb1qchange000']}},
                    ],
                }
            }
        else:
            return {'inputs': [{'partial_signatures': {'a': 's'}}], 'tx': {'vout': [{'value': 0.0006, 'scriptPubKey': {'addresses': ['tb1qseller111']}}]}}
    if method == 'analyzepsbt':
        return {'fee':0.000065}
    if method == 'combinepsbt':
        return 'merged'
    if method == 'gettransaction':
        txid = params[0]
        if txid == 'tx2':
            return {'confirmations':2,'details':[{'vout':1,'label':'escrow:order1'}]}
        return {'confirmations':2,'details':[{'vout':0,'label':'escrow:order1'}]}
    if method == 'gettxout':
        txid, vout = params
        if txid == 'tx2' and vout == 1:
            return {'value':0.000065}
        return {'value':0.0006}
    if method == 'finalizepsbt':
        return {'hex':'deadbeef','complete':True}
    if method == 'sendrawtransaction':
        return 'txid123'
    if method == 'bumpfee':
        return {'psbt':'rbfBase'}
    if method == 'estimatesmartfee':
        return {'feerate':0.0001}
    return {}


def stub_rpc_import_fail(method, params=None):
    if method == 'importdescriptors':
        return [{'success': False}]
    return stub_rpc(method, params)


def stub_utxos(label, min_conf):
    return [{'txid': 'tx1', 'vout': 0, 'amount': 0.000665}]

def stub_utxos_insuf(label, min_conf):
    return [{'txid': 'tx1', 'vout': 0, 'amount': 0.000615}]

def stub_utxos_short(label, min_conf):
    return [{'txid': 'tx1', 'vout': 0, 'amount': 0.0006}]


def test_create_order_import_descriptor_failure(monkeypatch):
    client = create_client(monkeypatch)
    import python_api
    import importlib
    rpc_module = importlib.import_module('python_api.rpc')
    orders_module = importlib.import_module('python_api.routes.orders')
    monkeypatch.setattr(rpc_module, 'rpc', stub_rpc_import_fail)
    monkeypatch.setattr(orders_module, 'rpc', stub_rpc_import_fail)
    headers = {'x-api-key': 'testkey'}
    body = {
        'order_id': 'orderF',
        'buyer': {'xpub': 'X'},
        'seller': {'xpub': 'Y'},
        'escrow': {'xpub': 'Z'},
        'min_conf': 2,
        'amount_sat': 60000,
    }
    r = client.post('/orders', json=body, headers=headers)
    assert r.status_code == 500
    import db
    assert db.get_order('orderF') is None


def test_payout_quote(monkeypatch):
    client=create_client(monkeypatch)
    import python_api
    import importlib
    rpc_module = importlib.import_module('python_api.rpc')
    orders_module = importlib.import_module('python_api.routes.orders')
    psbt_module = importlib.import_module('python_api.routes.psbt')
    admin_module = importlib.import_module('python_api.routes.admin')
    monkeypatch.setattr(rpc_module, 'rpc', stub_rpc)
    monkeypatch.setattr(python_api, 'rpc', stub_rpc)
    monkeypatch.setattr(orders_module, 'rpc', stub_rpc)
    monkeypatch.setattr(orders_module, 'find_utxos_for_label', stub_utxos)
    monkeypatch.setattr(psbt_module, 'rpc', stub_rpc)
    headers={'x-api-key':'testkey'}
    body={'order_id':'orderQ','buyer':{'xpub':'X'},'seller':{'xpub':'Y'},'escrow':{'xpub':'Z'},'min_conf':2,'amount_sat':60000}
    r=client.post('/orders', json=body, headers=headers)
    assert r.status_code==200
    r=client.get('/orders/orderQ/status', headers=headers)
    status = r.json()
    assert status['state']=='escrow_funded'
    assert status['fee_est_sat']==1500
    r=client.post('/orders/orderQ/payout_quote', json={'address':'tb1qseller111'}, headers=headers)
    assert r.status_code==200, r.text
    assert r.json()=={'fee_sat':6500}

def test_full_payout_flow(monkeypatch):
    client=create_client(monkeypatch)
    import python_api
    import importlib
    rpc_module = importlib.import_module('python_api.rpc')
    orders_module = importlib.import_module('python_api.routes.orders')
    psbt_module = importlib.import_module('python_api.routes.psbt')
    admin_module = importlib.import_module('python_api.routes.admin')
    monkeypatch.setattr(rpc_module, 'rpc', stub_rpc)
    monkeypatch.setattr(python_api, 'rpc', stub_rpc)
    monkeypatch.setattr(orders_module, 'rpc', stub_rpc)
    monkeypatch.setattr(orders_module, 'find_utxos_for_label', stub_utxos)
    monkeypatch.setattr(psbt_module, 'rpc', stub_rpc)
    monkeypatch.setattr(psbt_module, 'find_utxos_for_label', stub_utxos)
    monkeypatch.setattr(admin_module, 'rpc', stub_rpc)
    headers={'x-api-key':'testkey'}
    body={'order_id':'order1','buyer':{'xpub':'X'},'seller':{'xpub':'Y'},'escrow':{'xpub':'Z'},'min_conf':2,'amount_sat':60000}
    r=client.post('/orders', json=body, headers=headers)
    assert r.status_code==200
    r=client.get('/orders/order1/status', headers=headers)
    st=r.json()
    assert st['state']=='escrow_funded'
    assert st['fee_est_sat']==1500
    r=client.post('/orders/order1/payout_quote', json={'address':'tb1qseller111'}, headers=headers)
    assert r.status_code==200, r.text
    quote = r.json()
    assert quote['fee_sat']==6500
    total = 60000 + quote['fee_sat']
    r=client.post('/psbt/build', json={'order_id':'order1','outputs':{'tb1qseller111':total}}, headers=headers)
    assert r.status_code==200, r.text
    assert r.json()['psbt']=='psbtP'
    r=client.post('/psbt/merge', json={'order_id':'order1','partials':['cDE=','cDI=']}, headers=headers)
    assert r.status_code==200, r.text
    assert r.json()['psbt']=='merged'
    r=client.post('/psbt/decode', json={'psbt':'merged'}, headers=headers)
    assert r.status_code==200, r.text
    dec=r.json()
    assert dec['sign_count']==2
    assert dec['outputs']['tb1qseller111']==60000
    assert dec['fee_sat']==6500
    r=client.post('/psbt/finalize', json={'order_id':'order1','psbt':'merged','state':'completed'}, headers=headers)
    assert r.status_code==200, r.text
    assert r.json()['hex']=='deadbeef'
    r=client.post('/tx/broadcast', json={'order_id':'order1','hex':'deadbeef','state':'completed'}, headers=headers)
    assert r.status_code==200, r.text
    assert r.json()['txid']=='txid123'
    from db import set_outputs as _set_outputs
    _set_outputs('order1', {'tb1qseller111':60000, 'tb1qchange000':5000}, 'payout')
    r=client.post('/tx/bumpfee', json={'order_id':'order1','target_conf':2}, headers=headers)
    assert r.status_code==200, r.text
    psbt = r.json()['psbt']
    assert psbt=='rbfBase'
    # finalize bumped transaction
    r=client.post('/tx/bumpfee/finalize', json={'order_id':'order1','psbt':psbt}, headers=headers)
    assert r.status_code==200, r.text
    assert r.json()['txid']=='txid123'


def test_payout_build_insufficient(monkeypatch):
    client = create_client(monkeypatch)
    import python_api
    import importlib
    rpc_module = importlib.import_module('python_api.rpc')
    orders_module = importlib.import_module('python_api.routes.orders')
    psbt_module = importlib.import_module('python_api.routes.psbt')
    monkeypatch.setattr(rpc_module, 'rpc', stub_rpc)
    monkeypatch.setattr(python_api, 'rpc', stub_rpc)
    monkeypatch.setattr(orders_module, 'rpc', stub_rpc)
    monkeypatch.setattr(orders_module, 'find_utxos_for_label', stub_utxos_insuf)
    monkeypatch.setattr(psbt_module, 'rpc', stub_rpc)
    monkeypatch.setattr(psbt_module, 'find_utxos_for_label', stub_utxos_insuf)
    headers = {'x-api-key': 'testkey'}
    body = {'order_id': 'orderI', 'buyer': {'xpub': 'X'}, 'seller': {'xpub': 'Y'}, 'escrow': {'xpub': 'Z'}, 'min_conf': 2, 'amount_sat': 60000}
    r = client.post('/orders', json=body, headers=headers)
    assert r.status_code == 200
    r = client.post('/orders/orderI/payout_quote', json={'address': 'tb1qseller111'}, headers=headers)
    assert r.status_code == 200
    fee = r.json()['fee_sat']
    total = 60000 + fee
    r = client.post('/psbt/build', json={'order_id': 'orderI', 'outputs': {'tb1qseller111': total}}, headers=headers)
    assert r.status_code == 400


def test_refund_psbt(monkeypatch):
    client=create_client(monkeypatch)
    import python_api
    import importlib
    rpc_module = importlib.import_module('python_api.rpc')
    orders_module = importlib.import_module('python_api.routes.orders')
    psbt_module = importlib.import_module('python_api.routes.psbt')
    monkeypatch.setattr(rpc_module, 'rpc', stub_rpc)
    monkeypatch.setattr(python_api, 'rpc', stub_rpc)
    monkeypatch.setattr(orders_module, 'rpc', stub_rpc)
    monkeypatch.setattr(orders_module, 'find_utxos_for_label', stub_utxos)
    monkeypatch.setattr(psbt_module, 'rpc', stub_rpc)
    monkeypatch.setattr(psbt_module, 'find_utxos_for_label', stub_utxos)
    headers={'x-api-key':'testkey'}
    body={'order_id':'order2','buyer':{'xpub':'X'},'seller':{'xpub':'Y'},'escrow':{'xpub':'Z'},'min_conf':2,'amount_sat':60000}
    r=client.post('/orders', json=body, headers=headers)
    assert r.status_code==200
    r=client.get('/orders/order2/status', headers=headers)
    st=r.json()
    assert st['state']=='escrow_funded'
    assert st['fee_est_sat']==1500
    r=client.post('/psbt/build_refund', json={'order_id':'order2','address':'tb1qrefunded0'}, headers=headers)
    assert r.status_code==200, r.text
    assert r.json()['psbt']=='psbtR'


def test_underfunded_deposit(monkeypatch):
    client = create_client(monkeypatch)
    import python_api
    import importlib
    rpc_module = importlib.import_module('python_api.rpc')
    orders_module = importlib.import_module('python_api.routes.orders')
    psbt_module = importlib.import_module('python_api.routes.psbt')
    monkeypatch.setattr(rpc_module, 'rpc', stub_rpc)
    monkeypatch.setattr(python_api, 'rpc', stub_rpc)
    monkeypatch.setattr(orders_module, 'rpc', stub_rpc)
    monkeypatch.setattr(orders_module, 'find_utxos_for_label', stub_utxos_short)
    monkeypatch.setattr(psbt_module, 'rpc', stub_rpc)
    monkeypatch.setattr(psbt_module, 'find_utxos_for_label', stub_utxos_short)
    headers = {'x-api-key': 'testkey'}
    body = {'order_id': 'orderU', 'buyer': {'xpub': 'X'}, 'seller': {'xpub': 'Y'}, 'escrow': {'xpub': 'Z'}, 'min_conf': 2, 'amount_sat': 60000}
    r = client.post('/orders', json=body, headers=headers)
    assert r.status_code == 200
    r = client.get('/orders/orderU/status', headers=headers)
    st = r.json()
    assert st['state'] == 'awaiting_deposit'
    assert st['funding']['total_sat'] == 60000
    assert st['funding']['shortfall_sat'] == 1500


def test_psbt_decode_multi_input(monkeypatch):
    client = create_client(monkeypatch)
    import python_api
    import importlib
    rpc_module = importlib.import_module('python_api.rpc')
    orders_module = importlib.import_module('python_api.routes.orders')
    psbt_module = importlib.import_module('python_api.routes.psbt')

    def stub_rpc_multi(method, params=None):
        if method == 'decodepsbt' and params[0] == 'multi':
            return {
                'tx': {'vout': [{'value': 0.00055, 'scriptPubKey': {'addresses': ['tb1qseller111']}}]},
                'inputs': [
                    {'partial_signatures': {'a': 'sig'}},
                    {'partial_signatures': {'b': 'sig', 'c': 'sig'}},
                ],
            }
        return stub_rpc(method, params)

    monkeypatch.setattr(python_api, 'rpc', stub_rpc_multi)
    monkeypatch.setattr(rpc_module, 'rpc', stub_rpc_multi)
    monkeypatch.setattr(psbt_module, 'rpc', stub_rpc_multi)
    headers = {'x-api-key': 'testkey'}
    r = client.post('/psbt/decode', json={'psbt': 'multi'}, headers=headers)
    assert r.status_code == 200, r.text
    assert r.json()['sign_count'] == 3


def test_stuck_worker_watch_only(monkeypatch):
    client = create_client(monkeypatch)
    import python_api
    import python_api.workers as workers
    import python_api.routes.psbt as psbt_routes
    import python_api.routes.admin as admin_routes

    order = {
        'order_id': 'orderW',
        'state': 'signing',
        'deadline_ts': 1,
        'created_at': 0,
        'output_type': 'payout',
    }

    def list_orders(states):
        return [order]

    def get_partials(order_id):
        return ['partial1']

    def rpc_stub(method, params=None):
        if method == 'combinepsbt':
            return 'oneSig'
        if method == 'walletprocesspsbt':
            return {'psbt': 'oneSig'}
        if method == 'decodepsbt':
            return {'inputs': [{'partial_signatures': {'a': 'sig'}}]}
        return stub_rpc(method, params)

    finalize_called = []
    broadcast_called = []

    def finalize_stub(req):
        finalize_called.append(req)
        return {'hex': 'deadbeef', 'complete': True}

    def broadcast_stub(req):
        broadcast_called.append(req)
        return {'txid': 'txid123'}

    class Logger:
        def __init__(self):
            self.events = []
            self.errors = []
        def info(self, event, **kw):
            self.events.append((event, kw))
        def warning(self, *a, **kw):
            pass
        def error(self, event, **kw):
            self.errors.append((event, kw))

    logger = Logger()

    monkeypatch.setattr(workers, 'rpc', rpc_stub)
    monkeypatch.setattr(workers.db, 'list_orders_by_states', list_orders)
    monkeypatch.setattr(workers.db, 'get_partials', get_partials)
    monkeypatch.setattr(psbt_routes, 'psbt_finalize', finalize_stub)
    monkeypatch.setattr(admin_routes, 'tx_broadcast', broadcast_stub)
    monkeypatch.setattr(workers, 'log', logger)

    def stop(*a, **kw):
        raise SystemExit

    monkeypatch.setattr(workers.time, 'sleep', stop)

    try:
        workers._stuck_worker()
    except SystemExit:
        pass

    assert finalize_called == []
    assert broadcast_called == []
    assert logger.errors == []
    assert ('watch_only_no_signatures', {'order_id': 'orderW', 'sign_count': 1}) in logger.events
    assert ('deadline_escalation_skipped', {'order_id': 'orderW', 'sign_count': 1}) in logger.events
    assert python_api.STUCK_COUNTER.labels(state='insufficient_signatures')._value.get() == 1
