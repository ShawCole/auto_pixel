#!/usr/bin/env bash
set -euo pipefail
curl -s -X POST 'https://hook.thynkdata.com/pixel_import.php?client=AcquireUp' \
  -H 'Content-Type: application/json' \
  --data-binary @/tmp/acquireup_sample.json
