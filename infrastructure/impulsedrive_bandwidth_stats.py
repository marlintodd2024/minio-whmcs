#!/usr/bin/env python3
"""
ImpulseDrive Bandwidth Stats Generator

Parses Nginx impulsedrive_bandwidth.log and maintains cumulative monthly
per-bucket bandwidth stats. Writes JSON for WHMCS consumption.

Runs hourly via cron on the MinIO server.

Deploy to: /usr/local/bin/impulsedrive_bandwidth_stats.py
Cron: 5 * * * * /usr/bin/python3 /usr/local/bin/impulsedrive_bandwidth_stats.py

@package ImpulseMinio
@version 1.0.0
"""
import json
import re
import os
from datetime import datetime, timezone
from collections import defaultdict

LOG_FILE = "/var/log/nginx/impulsedrive_bandwidth.log"
OUTPUT_FILE = "/var/www/impulsedrive-stats/bandwidth.json"
STATE_FILE = "/var/www/impulsedrive-stats/bandwidth_state.json"

# Load existing state (cumulative monthly totals)
state = {"month": None, "last_offset": 0, "buckets": {}}
if os.path.exists(STATE_FILE):
    try:
        with open(STATE_FILE, 'r') as f:
            state = json.load(f)
    except (json.JSONDecodeError, IOError):
        pass

current_month = datetime.now(tz=timezone.utc).strftime('%Y-%m')

# Reset counters on new month
if state.get("month") != current_month:
    state = {"month": current_month, "last_offset": 0, "buckets": {}}

bucket_bytes = defaultdict(int, {k: v["bytes_sent"] for k, v in state.get("buckets", {}).items()})
bucket_requests = defaultdict(int, {k: v["requests"] for k, v in state.get("buckets", {}).items()})

# Read only new lines since last run (using file offset)
last_offset = state.get("last_offset", 0)
new_lines = 0

try:
    file_size = os.path.getsize(LOG_FILE)

    # If file is smaller than offset, it was rotated - read from start
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

            # Extract bucket name from host
            # CDN: bucketname.region.impulsedrive.io -> bucketname
            # API: region.impulsedrive.io -> _api
            if host.count('.') <= 2:
                bucket = "_api"
            else:
                bucket = host.split('.')[0]

            # Count successful responses for egress
            if 200 <= status < 400:
                bucket_bytes[bucket] += bytes_sent
                bucket_requests[bucket] += 1

        new_offset = f.tell()

except FileNotFoundError:
    new_offset = 0

# Build output
output = {
    "generated_at": datetime.now(tz=timezone.utc).strftime('%Y-%m-%d %H:%M:%S UTC'),
    "month": current_month,
    "new_lines_parsed": new_lines,
    "buckets": {}
}

for bucket in sorted(set(list(bucket_bytes.keys()) + list(bucket_requests.keys()))):
    output["buckets"][bucket] = {
        "bytes_sent": bucket_bytes[bucket],
        "requests": bucket_requests[bucket]
    }

# Write public JSON (for WHMCS)
os.makedirs(os.path.dirname(OUTPUT_FILE), exist_ok=True)
with open(OUTPUT_FILE, 'w') as f:
    json.dump(output, f, indent=2)

# Save state (for incremental parsing)
state_out = {
    "month": current_month,
    "last_offset": new_offset,
    "buckets": output["buckets"]
}
with open(STATE_FILE, 'w') as f:
    json.dump(state_out, f, indent=2)

print(f"[{datetime.now(tz=timezone.utc).strftime('%Y-%m-%d %H:%M:%S')}] Stats updated: {len(output['buckets'])} buckets, {new_lines} new lines")
