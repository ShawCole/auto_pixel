#!/usr/bin/env bash
set -euo pipefail
LOCK_FILE=/opt/auto-pixel/upsert_pixel_daily_stats.lock
/usr/bin/flock -n "$LOCK_FILE" -c "/usr/bin/mysql --silent --skip-column-names -e 'CALL pixel.upsert_pixel_daily_stats();' >/dev/null 2>&1"
