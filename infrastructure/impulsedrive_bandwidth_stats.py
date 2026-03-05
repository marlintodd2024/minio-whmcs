#!/usr/bin/env python3
"""
ImpulseDrive Stats Generator — Bandwidth + Prometheus Metrics

Parses Nginx bandwidth log and MinIO Prometheus endpoint to provide
per-bucket stats for WHMCS consumption. Runs hourly via cron.

Deploy to: /usr/local/bin/impulsedrive_bandwidth_stats.py
Cron: 5 * * * * /usr/bin/python3 /usr/local/bin/impulsedrive_bandwidth_stats.py

@package ImpulseMinio
@version 2.0.0
"""
import json
import re
import os
import urllib.request
from datetime import datetime, timezone
from collections import defaultdict

LOG_FILE = "/var/log/nginx/impulsedrive_bandwidth.log"
OUTPUT_FILE = "/var/www/impulsedrive-stats/bandwidth.json"
STATE_FILE = "/var/www/impulsedrive-stats/bandwidth_state.json"
PROMETHEUS_URL = "http://127.0.0.1:9000/minio/v2/metrics/bucket"

# ─── Load existing state ───
state = {"month": None, "last_offset": 0, "buckets": {}}
if os.path.exists(STATE_FILE):
    try:
        with open(STATE_FILE, 'r') as f:
            state = json.load(f)
    except (json.JSONDecodeError, IOError):
        pass

current_month = datetime.now(tz=timezone.utc).strftime('%Y-%m')

if state.get("month") != current_month:
    state = {"month": current_month, "last_offset": 0, "buckets": {}}

bucket_bytes = defaultdict(int, {k: v.get("bytes_sent", 0) for k, v in state.get("buckets", {}).items()})
bucket_requests = defaultdict(int, {k: v.get("requests", 0) for k, v in state.get("buckets", {}).items()})

# ─── Parse Nginx log (incremental) ───
last_offset = state.get("last_offset", 0)
new_lines = 0

try:
    file_size = os.path.getsize(LOG_FILE)
    if file_size < last_offset:
        last_offset = 0

    with open(LOG_FILE, 'r') as f:
        f.seek(last_offset)
        for line in f:
            new_lines += 1
            match = re.match(
                r'^(\S+)\s+\S+\s+\[([^\]]+)\]\s+"(\S+)\s+\S+"\s+(\d+)\s+(\d+)$',
                line.strip()
            )
            if not match:
                continue
            host, time_str, method, status, bytes_sent = match.groups()
            bytes_sent = int(bytes_sent)
            status = int(status)
            if host.count('.') <= 2:
                bucket = "_api"
            else:
                bucket = host.split('.')[0]
            if 200 <= status < 400:
                bucket_bytes[bucket] += bytes_sent
                bucket_requests[bucket] += 1
        new_offset = f.tell()
except FileNotFoundError:
    new_offset = 0

# ─── Fetch Prometheus metrics ───
prometheus = {}
try:
    req = urllib.request.urlopen(PROMETHEUS_URL, timeout=10)
    prom_text = req.read().decode('utf-8')
    for line in prom_text.split('\n'):
        if line.startswith('#') or not line.strip():
            continue
        m = re.match(r'^(minio_bucket_\w+)\{bucket="([^"]+)"', line)
        if not m:
            continue
        metric_name, bucket_name = m.groups()
        parts = line.rsplit(' ', 1)
        if len(parts) == 2:
            try:
                val = float(parts[1])
            except ValueError:
                continue
            if bucket_name not in prometheus:
                prometheus[bucket_name] = {}
            prometheus[bucket_name][metric_name] = val
except Exception as e:
    print(f"WARNING: Prometheus fetch failed: {e}")

# ─── Build output ───
output = {
    "generated_at": datetime.now(tz=timezone.utc).strftime('%Y-%m-%d %H:%M:%S UTC'),
    "month": current_month,
    "new_lines_parsed": new_lines,
    "buckets": {}
}

all_buckets = sorted(set(list(bucket_bytes.keys()) + list(bucket_requests.keys()) + list(prometheus.keys())))

for bucket in all_buckets:
    prom = prometheus.get(bucket, {})
    output["buckets"][bucket] = {
        "bytes_sent": bucket_bytes.get(bucket, 0),
        "requests": bucket_requests.get(bucket, 0),
        "storage_bytes": int(prom.get("minio_bucket_usage_total_bytes", 0)),
        "object_count": int(prom.get("minio_bucket_usage_object_total", 0)),
        "traffic_sent_bytes": int(prom.get("minio_bucket_traffic_sent_bytes", 0)),
        "traffic_received_bytes": int(prom.get("minio_bucket_traffic_received_bytes", 0)),
        "replication_received_bytes": int(prom.get("minio_bucket_replication_received_bytes", 0)),
        "replication_received_count": int(prom.get("minio_bucket_replication_received_count", 0)),
    }

os.makedirs(os.path.dirname(OUTPUT_FILE), exist_ok=True)
with open(OUTPUT_FILE, 'w') as f:
    json.dump(output, f, indent=2)

state_out = {
    "month": current_month,
    "last_offset": new_offset,
    "buckets": {k: {"bytes_sent": v["bytes_sent"], "requests": v["requests"]} for k, v in output["buckets"].items()}
}
with open(STATE_FILE, 'w') as f:
    json.dump(state_out, f, indent=2)

print(f"[{datetime.now(tz=timezone.utc).strftime('%Y-%m-%d %H:%M:%S')}] Stats updated: {len(output['buckets'])} buckets, {new_lines} new lines, prometheus: {len(prometheus)} buckets")
