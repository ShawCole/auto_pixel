#!/usr/bin/env bash
set -euo pipefail
DB="VettaFi"
MYSQL="mysql -h 34.26.61.148 -u root -p'AccuPoint01!'"
LOG="/var/log/auto-pixel/backfill_vettafi.log"

echo "[$(date -Iseconds)] Backfill start" | tee -a "$LOG"

# 1) Latest-event-per-uuid backfill for personal/contact/email/job/phones
$MYSQL "$DB" -e "
UPDATE superpixel_visitors v
JOIN (
  SELECT r1.*
  FROM superpixel_resolution_log r1
  JOIN (
    SELECT uuid, MAX(id) AS max_id
    FROM superpixel_resolution_log
    WHERE uuid IS NOT NULL AND uuid!='' AND uuid!='null'
    GROUP BY uuid
  ) m ON r1.uuid=m.uuid AND r1.id=m.max_id
) r ON v.uuid = r.uuid
SET
  v.first_name = COALESCE(NULLIF(v.first_name,''), NULLIF(r.first_name,'')),
  v.last_name = COALESCE(NULLIF(v.last_name,''), NULLIF(r.last_name,'')),
  v.personal_address = COALESCE(NULLIF(v.personal_address,''), NULLIF(r.personal_address,'')),
  v.personal_city = COALESCE(NULLIF(v.personal_city,''), NULLIF(r.personal_city,'')),
  v.personal_state = COALESCE(NULLIF(v.personal_state,''), NULLIF(r.personal_state,'')),
  v.personal_zip = COALESCE(NULLIF(v.personal_zip,''), NULLIF(r.personal_zip,'')),
  v.personal_zip4 = COALESCE(NULLIF(v.personal_zip4,''), NULLIF(r.personal_zip4,'')),
  v.age_range = COALESCE(NULLIF(v.age_range,''), NULLIF(r.age_range,'')),
  v.children = COALESCE(NULLIF(v.children,''), NULLIF(r.children,'')),
  v.gender = COALESCE(NULLIF(v.gender,''), NULLIF(r.gender,'')),
  v.homeowner = COALESCE(NULLIF(v.homeowner,''), NULLIF(r.homeowner,'')),
  v.married = COALESCE(NULLIF(v.married,''), NULLIF(r.married,'')),
  v.net_worth = COALESCE(NULLIF(v.net_worth,''), NULLIF(r.net_worth,'')),
  v.income_range = COALESCE(NULLIF(v.income_range,''), NULLIF(r.income_range,'')),
  v.direct_number = COALESCE(NULLIF(v.direct_number,''), NULLIF(r.direct_number,'')),
  v.direct_number_dnc = COALESCE(NULLIF(v.direct_number_dnc,''), NULLIF(r.direct_number_dnc,'')),
  v.mobile_phone = COALESCE(NULLIF(v.mobile_phone,''), NULLIF(r.mobile_phone,'')),
  v.mobile_phone_dnc = COALESCE(NULLIF(v.mobile_phone_dnc,''), NULLIF(r.mobile_phone_dnc,'')),
  v.personal_phone = COALESCE(NULLIF(v.personal_phone,''), NULLIF(r.personal_phone,'')),
  v.personal_phone_dnc = COALESCE(NULLIF(v.personal_phone_dnc,''), NULLIF(r.personal_phone_dnc,'')),
  v.business_email = COALESCE(NULLIF(v.business_email,''), NULLIF(r.business_email,'')),
  v.personal_emails = COALESCE(NULLIF(v.personal_emails,''), NULLIF(r.personal_emails,'')),
  v.deep_verified_emails = COALESCE(NULLIF(v.deep_verified_emails,''), NULLIF(r.deep_verified_emails,'')),
  v.sha256_personal_email = COALESCE(NULLIF(v.sha256_personal_email,''), NULLIF(r.sha256_personal_email,'')),
  v.sha256_business_email = COALESCE(NULLIF(v.sha256_business_email,''), NULLIF(r.sha256_business_email,'')),
  v.hem_sha256 = COALESCE(NULLIF(v.hem_sha256,''), NULLIF(r.hem_sha256,'')),
  v.job_title = COALESCE(NULLIF(v.job_title,''), NULLIF(r.job_title,'')),
  v.headline = COALESCE(NULLIF(v.headline,''), NULLIF(r.headline,'')),
  v.department = COALESCE(NULLIF(v.department,''), NULLIF(r.department,'')),
  v.seniority_level = COALESCE(NULLIF(v.seniority_level,''), NULLIF(r.seniority_level,'')),
  v.inferred_years_experience = COALESCE(NULLIF(v.inferred_years_experience,''), NULLIF(r.inferred_years_experience,'')),
  v.valid_phones = COALESCE(NULLIF(v.valid_phones,''), NULLIF(r.valid_phones,''));" | tee -a "$LOG"

# 2) Fallback from raw_events JSON (VettaFi)
$MYSQL "$DB" -e "
UPDATE superpixel_visitors v
JOIN (
  SELECT re.uuid,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.PERSONAL_ADDRESS')) AS personal_address,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.PERSONAL_CITY')) AS personal_city,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.PERSONAL_STATE')) AS personal_state,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.PERSONAL_ZIP')) AS personal_zip,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.PERSONAL_ZIP4')) AS personal_zip4,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.AGE_RANGE')) AS age_range,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.CHILDREN')) AS children,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.GENDER')) AS gender,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.HOMEOWNER')) AS homeowner,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.MARRIED')) AS married,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.NET_WORTH')) AS net_worth,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.INCOME_RANGE')) AS income_range,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.DIRECT_NUMBER')) AS direct_number,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.DIRECT_NUMBER_DNC')) AS direct_number_dnc,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.MOBILE_PHONE')) AS mobile_phone,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.MOBILE_PHONE_DNC')) AS mobile_phone_dnc,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.PERSONAL_PHONE')) AS personal_phone,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.PERSONAL_PHONE_DNC')) AS personal_phone_dnc,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.BUSINESS_EMAIL')) AS business_email,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.PERSONAL_EMAILS')) AS personal_emails,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.DEEP_VERIFIED_EMAILS')) AS deep_verified_emails,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.SHA256_PERSONAL_EMAIL')) AS sha256_personal_email,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.SHA256_BUSINESS_EMAIL')) AS sha256_business_email,
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.hem_sha256')), JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.HEM_SHA256'))) AS hem_sha256,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.JOB_TITLE')) AS job_title,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.HEADLINE')) AS headline,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.DEPARTMENT')) AS department,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.SENIORITY_LEVEL')) AS seniority_level,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.INFERRED_YEARS_EXPERIENCE')) AS inferred_years_experience,
    JSON_UNQUOTE(JSON_EXTRACT(CAST(re.payload AS JSON),'$.resolution.VALID_PHONES')) AS valid_phones
  FROM pixel.raw_events re
  JOIN (
     SELECT uuid, MAX(event_timestamp) AS max_ts
     FROM pixel.raw_events
     WHERE client_name='VettaFi' AND IFNULL(uuid,'')<>''
     GROUP BY uuid
  ) m ON re.uuid=m.uuid AND re.event_timestamp=m.max_ts
  WHERE re.client_name='VettaFi'
) j ON v.uuid=j.uuid
SET
  v.personal_address = COALESCE(NULLIF(v.personal_address,''), NULLIF(j.personal_address,'')),
  v.personal_city = COALESCE(NULLIF(v.personal_city,''), NULLIF(j.personal_city,'')),
  v.personal_state = COALESCE(NULLIF(v.personal_state,''), NULLIF(j.personal_state,'')),
  v.personal_zip = COALESCE(NULLIF(v.personal_zip,''), NULLIF(j.personal_zip,'')),
  v.personal_zip4 = COALESCE(NULLIF(v.personal_zip4,''), NULLIF(j.personal_zip4,'')),
  v.age_range = COALESCE(NULLIF(v.age_range,''), NULLIF(j.age_range,'')),
  v.children = COALESCE(NULLIF(v.children,''), NULLIF(j.children,'')),
  v.gender = COALESCE(NULLIF(v.gender,''), NULLIF(j.gender,'')),
  v.homeowner = COALESCE(NULLIF(v.homeowner,''), NULLIF(j.homeowner,'')),
  v.married = COALESCE(NULLIF(v.married,''), NULLIF(j.married,'')),
  v.net_worth = COALESCE(NULLIF(v.net_worth,''), NULLIF(j.net_worth,'')),
  v.income_range = COALESCE(NULLIF(v.income_range,''), NULLIF(j.income_range,'')),
  v.direct_number = COALESCE(NULLIF(v.direct_number,''), NULLIF(j.direct_number,'')),
  v.direct_number_dnc = COALESCE(NULLIF(v.direct_number_dnc,''), NULLIF(j.direct_number_dnc,'')),
  v.mobile_phone = COALESCE(NULLIF(v.mobile_phone,''), NULLIF(j.mobile_phone,'')),
  v.mobile_phone_dnc = COALESCE(NULLIF(v.mobile_phone_dnc,''), NULLIF(j.mobile_phone_dnc,'')),
  v.personal_phone = COALESCE(NULLIF(v.personal_phone,''), NULLIF(j.personal_phone,'')),
  v.personal_phone_dnc = COALESCE(NULLIF(v.personal_phone_dnc,''), NULLIF(j.personal_phone_dnc,'')),
  v.business_email = COALESCE(NULLIF(v.business_email,''), NULLIF(j.business_email,'')),
  v.personal_emails = COALESCE(NULLIF(v.personal_emails,''), NULLIF(j.personal_emails,'')),
  v.deep_verified_emails = COALESCE(NULLIF(v.deep_verified_emails,''), NULLIF(j.deep_verified_emails,'')),
  v.sha256_personal_email = COALESCE(NULLIF(v.sha256_personal_email,''), NULLIF(j.sha256_personal_email,'')),
  v.sha256_business_email = COALESCE(NULLIF(v.sha256_business_email,''), NULLIF(j.sha256_business_email,'')),
  v.hem_sha256 = COALESCE(NULLIF(v.hem_sha256,''), NULLIF(j.hem_sha256,'')),
  v.job_title = COALESCE(NULLIF(v.job_title,''), NULLIF(j.job_title,'')),
  v.headline = COALESCE(NULLIF(v.headline,''), NULLIF(j.headline,'')),
  v.department = COALESCE(NULLIF(v.department,''), NULLIF(j.department,'')),
  v.seniority_level = COALESCE(NULLIF(v.seniority_level,''), NULLIF(j.seniority_level,'')),
  v.inferred_years_experience = COALESCE(NULLIF(v.inferred_years_experience,''), NULLIF(j.inferred_years_experience,'')),
  v.valid_phones = COALESCE(NULLIF(v.valid_phones,''), NULLIF(j.valid_phones,''));
" | tee -a "$LOG"

echo "[$(date -Iseconds)] Backfill end" | tee -a "$LOG"
