set -euo pipefail
#!/usr/bin/env bash
DBHOST=34.31.66.104; DBUSER=root; DBPASS='AccuPoint01!'
CLIENTS=$(mysql -h $DBHOST -u $DBUSER -p$DBPASS --connect-timeout=5 --wait --reconnect -N -e "
  SELECT ps.client_name
  FROM pixel.pixel_sheets ps
  WHERE EXISTS (SELECT 1 FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=ps.client_name AND TABLE_NAME='superpixel_emails')
")
for DB in $CLIENTS; do
  mysql -h $DBHOST -u $DBUSER -p$DBPASS --connect-timeout=5 --wait --reconnect -e "
UPDATE \`$DB\`.superpixel_visitors v
JOIN (
  SELECT e.uuid, MAX(NULLIF(m.NPN,'')) AS NPN, MAX(NULLIF(m.CRD,'')) AS CRD
  FROM \`$DB\`.superpixel_emails e
  JOIN accupoint_solutions.match_emails m ON m.email_lc = e.email
  WHERE e.created_at > NOW() - INTERVAL 14 DAY
  GROUP BY e.uuid
) x ON x.uuid = v.uuid
SET v.npn = IFNULL(NULLIF(v.npn,''), x.NPN),
    v.crd = IFNULL(NULLIF(v.crd,''), x.CRD)
WHERE (v.npn IS NULL OR v.npn='') OR (v.crd IS NULL OR v.crd='');"
  echo "Enriched recent NPN/CRD for $DB"
done
