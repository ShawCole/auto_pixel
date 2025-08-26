#!/usr/bin/env bash
set -euo pipefail

export MYSQL_PWD='AccuPoint01!'
MYSQL="mysql --protocol=TCP -h 34.31.66.104 -u root"
log() { echo "[$(date -Iseconds)] $*"; }

# Ensure pixel_dev exists
$MYSQL -e "CREATE DATABASE IF NOT EXISTS pixel_dev;"

# Client list from dev
CLIENTS=$($MYSQL -N -B -e "
SELECT DISTINCT client_name
FROM pixel_dev.pixel_sheets
WHERE client_name REGEXP '^[A-Za-z0-9_]+$';
")

if [ -z "${CLIENTS}" ]; then
  log "No clients found in pixel_dev.pixel_sheets"
  exit 0
fi

for DB in ${CLIENTS}; do
  EXISTS=$($MYSQL -N -B -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB}';" | awk 'NR==1{print}')
  if [ "${EXISTS}" != "1" ]; then
    log "Skipping ${DB}: prod database not found"
    continue
  fi

  DEV_DB="${DB}_dev"
  log "Cloning ${DB} -> ${DEV_DB}"
  $MYSQL -e "CREATE DATABASE IF NOT EXISTS \`${DEV_DB}\`;"

  TABLES=$($MYSQL -N -B -e "
    SELECT TABLE_NAME
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA='${DB}' AND TABLE_TYPE='BASE TABLE';
  ")

  if [ -z "${TABLES}" ]; then
    log "No base tables in ${DB}; continuing"
    continue
  fi

  for T in ${TABLES}; do
    log "  - Rebuilding table ${T}"
    # Recreate schema
    $MYSQL -e "
      DROP TABLE IF EXISTS \`${DEV_DB}\`.\`${T}\`;
      CREATE TABLE \`${DEV_DB}\`.\`${T}\` LIKE \`${DB}\`.\`${T}\`;
    "

    # Copy data while excluding generated columns
    $MYSQL <<SQL || { log "    ! Copy failed for ${DB}.${T}; continuing"; continue; }
SET @src_db='${DB}', @dst_db='${DEV_DB}', @tbl='${T}';
SELECT GROUP_CONCAT(CONCAT('`', COLUMN_NAME, '`') ORDER BY ORDINAL_POSITION SEPARATOR ',')
INTO @cols
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@src_db AND TABLE_NAME=@tbl
  AND (EXTRA NOT LIKE '%GENERATED%' OR EXTRA IS NULL);

SET @sql = CONCAT('INSERT INTO `', @dst_db, '`.`', @tbl, '` (', @cols, ') ',
                   'SELECT ', @cols, ' FROM `', @src_db, '`.`', @tbl, '`');

PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SQL
  done

  SRC_CNT=$($MYSQL -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${DB}' AND TABLE_TYPE='BASE TABLE';" | awk 'NR==1{print}')
  DEV_CNT=$($MYSQL -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${DEV_DB}' AND TABLE_TYPE='BASE TABLE';" | awk 'NR==1{print}')
  log "Cloned ${SRC_CNT} tables to ${DEV_DB} (found ${DEV_CNT})"
done

log "Done."
