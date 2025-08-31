import os
import sys
import tempfile
import types
from fastapi.testclient import TestClient


def create_client():
    fd, db_path = tempfile.mkstemp()
    os.close(fd)
    os.environ["ORDERS_DB"] = db_path
    os.environ["ALLOW_ORIGINS"] = "http://test"
    os.environ["API_KEYS"] = "testkey"
    os.environ["RATE_LIMIT"] = "2/minute"
    sys.path.insert(0, os.path.dirname(__file__))
    stub = types.ModuleType("db")
    stub.init_db = lambda: None
    stub.count_pending_signatures = lambda: 0
    stub.list_orders_by_states = lambda states: []
    stub.get_partials = lambda order_id: []
    sys.modules["db"] = stub
    sys.modules.pop("api", None)
    import api
    return TestClient(api.app)


def test_rate_limit_blocks_after_threshold():
    client = create_client()
    headers = {"x-api-key": "testkey"}
    for _ in range(2):
        resp = client.get("/metrics", headers=headers)
        assert resp.status_code == 200
    resp = client.get("/metrics", headers=headers)
    assert resp.status_code == 429
