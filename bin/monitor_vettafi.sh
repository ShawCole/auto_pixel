#!/usr/bin/env bash
set -euo pipefail
DB="VettaFi"
MYSQL="mysql -h 34.26.61.148 -u root -p'AccuPoint01!'"
LOG="/var/log/auto-pixel/monitor_vettafi.log"

echo "[$(date -Iseconds)] Monitor start" | tee -a "$LOG"

$MYSQL "$DB" -e "
SELECT 'first_name' AS field, COUNT(*) AS missing FROM superpixel_visitors WHERE IFNULL(first_name,'')=''
UNION ALL SELECT 'last_name', COUNT(*) FROM superpixel_visitors WHERE IFNULL(last_name,'')=''
UNION ALL SELECT 'business_email', COUNT(*) FROM superpixel_visitors WHERE IFNULL(business_email,'')=''
UNION ALL SELECT 'company_name', COUNT(*) FROM superpixel_visitors WHERE IFNULL(company_name,'')='';
" | tee -a "$LOG"

# Trigger presence check
HAS=$($MYSQL -N -B -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='${DB}' AND TRIGGER_NAME='after_resolution_log_insert_visitor_update';")
if [ "$HAS" != "1" ]; then
  echo "[$(date -Iseconds)] Trigger missing! Re-applying." | tee -a "$LOG"
  $MYSQL "$DB" < /opt/auto-pixel/sql/visitor_trigger_safe.sql || echo "[$(date -Iseconds)] Failed to re-apply trigger" | tee -a "$LOG"
fi

echo "[$(date -Iseconds)] Monitor end" | tee -a "$LOG"
