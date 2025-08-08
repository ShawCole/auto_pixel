#!/usr/bin/env bash
set -euo pipefail
DBHOST=34.31.66.104; DBUSER=root; DBPASS='AccuPoint01!'
mysql -h $DBHOST -u $DBUSER -p"$DBPASS" -N -e "
SELECT client_name,
  (SELECT COUNT(*) FROM information_schema.TRIGGERS
   WHERE TRIGGER_SCHEMA=client_name AND TRIGGER_NAME='after_resolution_log_insert_visitor_update') AS has_trigger,
  (SELECT COUNT(*)>0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=client_name AND TABLE_NAME='superpixel_resolution_log' AND COLUMN_NAME='title') AS rl_title,
  (SELECT COUNT(*)>0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=client_name AND TABLE_NAME='superpixel_visitors' AND COLUMN_NAME='title') AS v_title,
  (SELECT COUNT(*) FROM \`client_name\`.superpixel_resolution_log
   WHERE created_at>NOW()-INTERVAL 24 HOUR) AS events_24h
FROM pixel.pixel_sheets;" | sed 's/\t/ | /g'
