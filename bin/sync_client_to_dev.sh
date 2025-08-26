#!/usr/bin/env bash
set -euo pipefail

export MYSQL_PWD='AccuPoint01!'
MYSQL="mysql --protocol=TCP -h 34.31.66.104 -u root -N -B"

if [ $# -ne 1 ]; then
  echo "Usage: $0 <ClientSchemaName>" >&2
  exit 1
fi

CLIENT="$1"
SRC="$CLIENT"
DST="${CLIENT}_dev"

# Ensure destination DB exists
$MYSQL -e "CREATE DATABASE IF NOT EXISTS \`$DST\`;"

# All base tables in source
TABLES=$($MYSQL -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$SRC' AND TABLE_TYPE='BASE TABLE';")

for T in $TABLES; do
  # Ensure dest table exists with identical schema
  $MYSQL -e "CREATE TABLE IF NOT EXISTS \`$DST\`.\`$T\` LIKE \`$SRC\`.\`$T\`;"
  # Truncate dest (fresh copy)
  $MYSQL -e "TRUNCATE TABLE \`$DST\`.\`$T\`;"

  # Copy data excluding generated columns
  $MYSQL -e "
SET @src='$SRC', @dst='$DST', @tbl='$T';
SET SESSION group_concat_max_len=1000000;
SELECT GROUP_CONCAT(CONCAT('`',COLUMN_NAME,'`') ORDER BY ORDINAL_POSITION) INTO @cols
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@src AND TABLE_NAME=@tbl
  AND (EXTRA IS NULL OR EXTRA NOT LIKE '%GENERATED%');

SET @sql = CONCAT('INSERT INTO `',@dst,'`.`',@tbl,'` (',@cols,') SELECT ',@cols,' FROM `',@src,'`.`',@tbl,'`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;"
done
