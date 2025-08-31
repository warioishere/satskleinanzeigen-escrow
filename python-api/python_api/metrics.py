from prometheus_client import Histogram, Counter, Gauge, REGISTRY


def _metric(name, factory):
    existing = REGISTRY._names_to_collectors.get(name)
    return existing if existing else factory()


RPC_HIST = _metric(
    'rpc_duration_seconds',
    lambda: Histogram('rpc_duration_seconds', 'Bitcoin Core RPC duration', ['method'])
)
WEBHOOK_COUNTER = _metric(
    'webhook_total',
    lambda: Counter('webhook_total', 'Webhook deliveries', ['status'])
)
PENDING_SIG = _metric(
    'pending_signatures',
    lambda: Gauge('pending_signatures', 'Open PSBT signatures')
)
STUCK_COUNTER = _metric(
    'stuck_orders_total',
    lambda: Counter('stuck_orders_total', 'Orders stuck beyond threshold', ['state'])
)
BROADCAST_FAIL = _metric(
    'broadcast_fail_total',
    lambda: Counter('broadcast_fail_total', 'Failed transaction broadcasts')
)
WEBHOOK_QUEUE_SIZE = _metric(
    'webhook_queue_size',
    lambda: Gauge('webhook_queue_size', 'Pending webhooks in queue')
)
