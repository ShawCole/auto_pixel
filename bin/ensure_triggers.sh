#!/usr/bin/env bash
set -euo pipefail
MYSQL="mysql -h 34.31.66.104 -u root -p'AccuPoint01!'"
LOG="/var/log/auto-pixel/ensure_triggers.log"
SQL_FILE="/opt/auto-pixel/sql/visitor_trigger_safe.sql"

# Iterate client DBs that have both required tables
DBS=$($MYSQL -N -B -e "SELECT TABLE_SCHEMA
FROM information_schema.TABLES
WHERE TABLE_NAME IN ('superpixel_resolution_log','superpixel_visitors')
GROUP BY TABLE_SCHEMA
HAVING COUNT(DISTINCT TABLE_NAME)=2;")

for DB in $DBS; do
  echo "[$(date -Iseconds)] Checking $DB" | tee -a "$LOG"

  # Ensure trigger exists
  HAS=$($MYSQL -N -B -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='${DB}' AND TRIGGER_NAME='after_resolution_log_insert_visitor_update';")
  if [ "$HAS" != "1" ]; then
    echo "[$(date -Iseconds)] Applying trigger to $DB" | tee -a "$LOG"
    $MYSQL "$DB" < "$SQL_FILE" || echo "[$(date -Iseconds)] Failed applying trigger to $DB" | tee -a "$LOG"
  fi

  # Ensure unique uuid index on visitors
  IDX=$($MYSQL -N -B -e "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='${DB}' AND TABLE_NAME='superpixel_visitors' AND INDEX_NAME IN ('uniq_uuid','uuid_unique','uuid');")
  if [ "$IDX" = "0" ]; then
    echo "[$(date -Iseconds)] Adding unique index on uuid for $DB" | tee -a "$LOG"
    $MYSQL "$DB" -e "ALTER TABLE superpixel_visitors ADD UNIQUE KEY uniq_uuid (uuid);" || true
  fi
done
