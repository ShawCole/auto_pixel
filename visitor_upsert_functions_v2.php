<?php
/**
 * visitor_upsert_functions_v2.php
 * Location: /opt/auto-pixel/visitor_upsert_functions_v2.php
 * 
 * ROLE: The Golden Record Manager (Full Schema Support)
 * 
 * STRATEGIES:
 * 1. Activity (URL, IP, Last Touch): Latest Non-Empty Wins (Overwrite).
 * 2. Identity, Demographics, Firmographics: Gap-Fill (Keep existing unless empty).
 * 3. Compliance: Latest Non-Null Wins.
 * 4. First-Touch (UTMs): First Value Wins.
 * 5. Metrics: Accumulate.
 */

function upsertVisitorV2($mysqli, $data, $debug_context = "unknown") {
    $uuid = $data['uuid'] ?? null;
    if (!$uuid) {
        if ($debug_context !== 'unknown') error_log("Visitor Upsert Skipped: No UUID ($debug_context)");
        return false;
    }

    // --- 1. FULL DATA MAPPING ---
    $map = [
        // IDENTIFIERS
        'uuid' => $uuid,
        'pair_ulid' => $data['pair_ulid'] ?? null,
        
        // IDENTITY (Gap-Fill)
        'first_name' => $data['first_name'] ?? $data['FIRST_NAME'] ?? null,
        'last_name' => $data['last_name'] ?? $data['LAST_NAME'] ?? null,
        'email_best' => $data['email_best'] ?? null,
        'personal_emails' => $data['personal_emails'] ?? $data['PERSONAL_EMAILS'] ?? null,
        'personal_verified_emails' => $data['personal_verified_emails'] ?? $data['PERSONAL_VERIFIED_EMAILS'] ?? null,
        'business_email' => $data['business_email'] ?? $data['BUSINESS_EMAIL'] ?? null,
        'business_verified_emails' => $data['business_verified_emails'] ?? $data['BUSINESS_VERIFIED_EMAILS'] ?? null,
        'deep_verified_emails' => $data['deep_verified_emails'] ?? $data['DEEP_VERIFIED_EMAILS'] ?? null,
        'sha256_personal_email' => $data['sha256_personal_email'] ?? $data['SHA256_PERSONAL_EMAIL'] ?? null,
        'sha256_business_email' => $data['sha256_business_email'] ?? $data['SHA256_BUSINESS_EMAIL'] ?? null,
        'hem_sha256' => $data['hem_sha256'] ?? $data['HEM_SHA256'] ?? null,

        // PHONES & DNC (Gap-Fill)
        'mobile_phone' => $data['mobile_phone'] ?? $data['MOBILE_PHONE'] ?? null,
        'mobile_phone_dnc' => $data['mobile_phone_dnc'] ?? $data['MOBILE_PHONE_DNC'] ?? null,
        'direct_number' => $data['direct_number'] ?? $data['DIRECT_NUMBER'] ?? null,
        'direct_number_dnc' => $data['direct_number_dnc'] ?? $data['DIRECT_NUMBER_DNC'] ?? null,
        'personal_phone' => $data['personal_phone'] ?? $data['PERSONAL_PHONE'] ?? null,
        'personal_phone_dnc' => $data['personal_phone_dnc'] ?? $data['PERSONAL_PHONE_DNC'] ?? null,
        'valid_phones' => $data['valid_phones'] ?? $data['VALID_PHONES'] ?? null,

        // LOCATION (Gap-Fill)
        'personal_address' => $data['personal_address'] ?? $data['PERSONAL_ADDRESS'] ?? null,
        'personal_city' => $data['personal_city'] ?? $data['PERSONAL_CITY'] ?? null,
        'personal_state' => $data['personal_state'] ?? $data['PERSONAL_STATE'] ?? null,
        'personal_zip' => $data['personal_zip'] ?? $data['PERSONAL_ZIP'] ?? null,
        'personal_zip4' => $data['personal_zip4'] ?? $data['PERSONAL_ZIP4'] ?? null,

        // DEMOGRAPHICS (Gap-Fill)
        'age_range' => $data['age_range'] ?? $data['AGE_RANGE'] ?? null,
        'gender' => $data['gender'] ?? $data['GENDER'] ?? null,
        'married' => $data['married'] ?? $data['MARRIED'] ?? null,
        'children' => $data['children'] ?? $data['CHILDREN'] ?? null,
        'homeowner' => $data['homeowner'] ?? $data['HOMEOWNER'] ?? null,
        'net_worth' => $data['net_worth'] ?? $data['NET_WORTH'] ?? null,
        'income_range' => $data['income_range'] ?? $data['INCOME_RANGE'] ?? null,

        // PROFESSIONAL & FIRMOGRAPHICS (Gap-Fill)
        'job_title' => $data['job_title'] ?? $data['JOB_TITLE'] ?? null,
        'headline' => $data['headline'] ?? $data['HEADLINE'] ?? null,
        'department' => $data['department'] ?? $data['DEPARTMENT'] ?? null,
        'seniority_level' => $data['seniority_level'] ?? $data['SENIORITY_LEVEL'] ?? null,
        'inferred_years_experience' => $data['inferred_years_experience'] ?? $data['INFERRED_YEARS_EXPERIENCE'] ?? null,
        'company_name' => $data['company_name'] ?? $data['COMPANY_NAME'] ?? null,
        'company_domain' => $data['company_domain'] ?? $data['COMPANY_DOMAIN'] ?? null,
        'company_phone' => $data['company_phone'] ?? $data['COMPANY_PHONE'] ?? null,
        'company_address' => $data['company_address'] ?? $data['COMPANY_ADDRESS'] ?? null,
        'company_city' => $data['company_city'] ?? $data['COMPANY_CITY'] ?? null,
        'company_state' => $data['company_state'] ?? $data['COMPANY_STATE'] ?? null,
        'company_zip' => $data['company_zip'] ?? $data['COMPANY_ZIP'] ?? null,
        'company_industry' => $data['company_industry'] ?? $data['COMPANY_INDUSTRY'] ?? null,
        'company_employee_count' => $data['company_employee_count'] ?? $data['COMPANY_EMPLOYEE_COUNT'] ?? null,
        'company_revenue' => $data['company_revenue'] ?? $data['COMPANY_REVENUE'] ?? null,
        'company_sic' => $data['company_sic'] ?? $data['COMPANY_SIC'] ?? null,
        'company_naics' => $data['company_naics'] ?? $data['COMPANY_NAICS'] ?? null,
        'company_description' => $data['company_description'] ?? $data['COMPANY_DESCRIPTION'] ?? null,
        'company_linkedin_url' => $data['company_linkedin_url'] ?? $data['COMPANY_LINKEDIN_URL'] ?? null,

        // SOCIAL (Gap-Fill)
        'linkedin_url' => $data['linkedin_url'] ?? $data['LINKEDIN_URL'] ?? null,
        'twitter_url' => $data['twitter_url'] ?? $data['TWITTER_URL'] ?? null,
        'facebook_url' => $data['facebook_url'] ?? $data['FACEBOOK_URL'] ?? null,

        // SKIPTRACE (Gap-Fill)
        'skiptrace_match_score' => $data['skiptrace_match_score'] ?? $data['SKIPTRACE_MATCH_SCORE'] ?? null,
        'skiptrace_name' => $data['skiptrace_name'] ?? $data['SKIPTRACE_NAME'] ?? null,
        'skiptrace_address' => $data['skiptrace_address'] ?? $data['SKIPTRACE_ADDRESS'] ?? null,
        'skiptrace_city' => $data['skiptrace_city'] ?? $data['SKIPTRACE_CITY'] ?? null,
        'skiptrace_state' => $data['skiptrace_state'] ?? $data['SKIPTRACE_STATE'] ?? null,
        'skiptrace_zip' => $data['skiptrace_zip'] ?? $data['SKIPTRACE_ZIP'] ?? null,
        'skiptrace_landline_numbers' => $data['skiptrace_landline_numbers'] ?? $data['SKIPTRACE_LANDLINE_NUMBERS'] ?? null,
        'skiptrace_wireless_numbers' => $data['skiptrace_wireless_numbers'] ?? $data['SKIPTRACE_WIRELESS_NUMBERS'] ?? null,
        'skiptrace_credit_rating' => $data['skiptrace_credit_rating'] ?? $data['SKIPTRACE_CREDIT_RATING'] ?? null,
        'skiptrace_dnc' => $data['skiptrace_dnc'] ?? $data['SKIPTRACE_DNC'] ?? null,
        'skiptrace_exact_age' => $data['skiptrace_exact_age'] ?? $data['SKIPTRACE_EXACT_AGE'] ?? null,
        'skiptrace_ethnic_code' => $data['skiptrace_ethnic_code'] ?? $data['SKIPTRACE_ETHNIC_CODE'] ?? null,
        'skiptrace_language_code' => $data['skiptrace_language_code'] ?? $data['SKIPTRACE_LANGUAGE_CODE'] ?? null,
        'skiptrace_ip' => $data['skiptrace_ip'] ?? $data['SKIPTRACE_IP'] ?? null,
        'skiptrace_b2b_address' => $data['skiptrace_b2b_address'] ?? $data['SKIPTRACE_B2B_ADDRESS'] ?? null,
        'skiptrace_b2b_phone' => $data['skiptrace_b2b_phone'] ?? $data['SKIPTRACE_B2B_PHONE'] ?? null,
        'skiptrace_b2b_source' => $data['skiptrace_b2b_source'] ?? $data['SKIPTRACE_B2B_SOURCE'] ?? null,
        'skiptrace_b2b_website' => $data['skiptrace_b2b_website'] ?? $data['SKIPTRACE_B2B_WEBSITE'] ?? null,

        // ACTIVITY CONTEXT (Latest Wins)
        'last_pixel_id' => $data['pixel_id'] ?? null,
        'last_ip_address' => $data['ip_address'] ?? null,
        'last_url' => $data['url'] ?? null,
        'last_title' => $data['title'] ?? null,
        'last_referrer' => $data['referrer'] ?? null,
        'last_user_agent' => $data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null,
        'last_event_type' => $data['event_type'] ?? null,
        
        // ATTRIBUTION - Last Touch (Latest Wins)
        'last_utm_source' => $data['utm_source'] ?? null,
        'last_utm_medium' => $data['utm_medium'] ?? null,
        'last_utm_campaign' => $data['utm_campaign'] ?? null,

        // ATTRIBUTION - First Touch (Gap-Fill Logic)
        'first_utm_source' => $data['utm_source'] ?? null,
        'first_utm_medium' => $data['utm_medium'] ?? null,
        'first_utm_campaign' => $data['utm_campaign'] ?? null,
        
        // COMPLIANCE (Latest Non-Null Wins)
        'npn' => $data['npn'] ?? null,
        'crd' => $data['crd'] ?? null,
        'npn_active' => $data['npn_active'] ?? null,
        'crd_active' => $data['crd_active'] ?? null,
        'APS_confidence_score' => $data['APS_confidence_score'] ?? null,
        'reason' => $data['reason'] ?? null,

        // METRICS (Accumulators)
        'total_time_on_site' => $data['time_on_page'] ?? 0,
    ];

    // --- 2. JSON ENCODING HELPER ---
    $toJson = function($key) use ($data) {
        $val = $data[$key] ?? $data[strtoupper($key)] ?? null;
        return (empty($val)) ? null : (is_string($val) ? $val : json_encode($val));
    };

    $map['company_name_history'] = $toJson('company_name_history');
    $map['job_title_history'] = $toJson('job_title_history');
    $map['education_history'] = $toJson('education_history');
    $map['social_connections'] = $toJson('social_connections');
    $map['skills'] = $toJson('skills');
    $map['interests'] = $toJson('interests');

    // --- 3. SQL CONSTRUCTION ---
    $cols = []; $vals = []; $types = "";
    foreach ($map as $col => $val) {
        $cols[] = "`$col`";
        $vals[] = "?";
        $types .= "s";
    }

    $sql = "INSERT INTO visitors (" . implode(',', $cols) . ", first_seen_at, event_count, last_seen_at) 
            VALUES (" . implode(',', $vals) . ", NOW(3), 1, NOW(3)) 
            ON DUPLICATE KEY UPDATE 
            
            -- METRICS
            event_count = event_count + 1,
            last_seen_at = NOW(3),
            total_time_on_site = total_time_on_site + VALUES(total_time_on_site),
            
            -- ACTIVITY & LAST TOUCH (Overwrite)
            pair_ulid = COALESCE(NULLIF(VALUES(pair_ulid), ''), pair_ulid),
            last_pixel_id = COALESCE(NULLIF(VALUES(last_pixel_id), ''), last_pixel_id),
            last_ip_address = COALESCE(NULLIF(VALUES(last_ip_address), ''), last_ip_address),
            last_url = COALESCE(NULLIF(VALUES(last_url), ''), last_url),
            last_title = COALESCE(NULLIF(VALUES(last_title), ''), last_title),
            last_referrer = COALESCE(NULLIF(VALUES(last_referrer), ''), last_referrer),
            last_event_type = COALESCE(NULLIF(VALUES(last_event_type), ''), last_event_type),
            last_user_agent = COALESCE(NULLIF(VALUES(last_user_agent), ''), last_user_agent),
            last_utm_source = COALESCE(NULLIF(VALUES(last_utm_source), ''), last_utm_source),
            last_utm_medium = COALESCE(NULLIF(VALUES(last_utm_medium), ''), last_utm_medium),
            last_utm_campaign = COALESCE(NULLIF(VALUES(last_utm_campaign), ''), last_utm_campaign),

            -- FIRST TOUCH (Gap-Fill: Only set if currently NULL)
            first_utm_source = COALESCE(first_utm_source, VALUES(first_utm_source)),
            first_utm_medium = COALESCE(first_utm_medium, VALUES(first_utm_medium)),
            first_utm_campaign = COALESCE(first_utm_campaign, VALUES(first_utm_campaign)),

            -- COMPLIANCE (Latest Non-Null)
            npn = COALESCE(NULLIF(VALUES(npn), ''), npn),
            crd = COALESCE(NULLIF(VALUES(crd), ''), crd),
            npn_active = COALESCE(VALUES(npn_active), npn_active),
            crd_active = COALESCE(VALUES(crd_active), crd_active),
            APS_confidence_score = COALESCE(VALUES(APS_confidence_score), APS_confidence_score),
            reason = COALESCE(NULLIF(VALUES(reason), ''), reason),

            -- IDENTITY & PROFILE (Gap-Fill: Keep existing unless empty)
            first_name = COALESCE(NULLIF(first_name, ''), VALUES(first_name)),
            last_name = COALESCE(NULLIF(last_name, ''), VALUES(last_name)),
            email_best = COALESCE(NULLIF(email_best, ''), VALUES(email_best)),
            personal_emails = COALESCE(NULLIF(personal_emails, ''), VALUES(personal_emails)),
            personal_verified_emails = COALESCE(NULLIF(personal_verified_emails, ''), VALUES(personal_verified_emails)),
            business_email = COALESCE(NULLIF(business_email, ''), VALUES(business_email)),
            business_verified_emails = COALESCE(NULLIF(business_verified_emails, ''), VALUES(business_verified_emails)),
            deep_verified_emails = COALESCE(NULLIF(deep_verified_emails, ''), VALUES(deep_verified_emails)),
            sha256_personal_email = COALESCE(NULLIF(sha256_personal_email, ''), VALUES(sha256_personal_email)),
            sha256_business_email = COALESCE(NULLIF(sha256_business_email, ''), VALUES(sha256_business_email)),
            hem_sha256 = COALESCE(NULLIF(hem_sha256, ''), VALUES(hem_sha256)),
            
            mobile_phone = COALESCE(NULLIF(mobile_phone, ''), VALUES(mobile_phone)),
            mobile_phone_dnc = COALESCE(NULLIF(mobile_phone_dnc, ''), VALUES(mobile_phone_dnc)),
            direct_number = COALESCE(NULLIF(direct_number, ''), VALUES(direct_number)),
            direct_number_dnc = COALESCE(NULLIF(direct_number_dnc, ''), VALUES(direct_number_dnc)),
            personal_phone = COALESCE(NULLIF(personal_phone, ''), VALUES(personal_phone)),
            personal_phone_dnc = COALESCE(NULLIF(personal_phone_dnc, ''), VALUES(personal_phone_dnc)),
            valid_phones = COALESCE(NULLIF(valid_phones, ''), VALUES(valid_phones)),

            personal_address = COALESCE(NULLIF(personal_address, ''), VALUES(personal_address)),
            personal_city = COALESCE(NULLIF(personal_city, ''), VALUES(personal_city)),
            personal_state = COALESCE(NULLIF(personal_state, ''), VALUES(personal_state)),
            personal_zip = COALESCE(NULLIF(personal_zip, ''), VALUES(personal_zip)),
            personal_zip4 = COALESCE(NULLIF(personal_zip4, ''), VALUES(personal_zip4)),

            age_range = COALESCE(NULLIF(age_range, ''), VALUES(age_range)),
            gender = COALESCE(NULLIF(gender, ''), VALUES(gender)),
            married = COALESCE(NULLIF(married, ''), VALUES(married)),
            children = COALESCE(NULLIF(children, ''), VALUES(children)),
            homeowner = COALESCE(NULLIF(homeowner, ''), VALUES(homeowner)),
            net_worth = COALESCE(NULLIF(net_worth, ''), VALUES(net_worth)),
            income_range = COALESCE(NULLIF(income_range, ''), VALUES(income_range)),

            job_title = COALESCE(NULLIF(job_title, ''), VALUES(job_title)),
            headline = COALESCE(NULLIF(headline, ''), VALUES(headline)),
            department = COALESCE(NULLIF(department, ''), VALUES(department)),
            seniority_level = COALESCE(NULLIF(seniority_level, ''), VALUES(seniority_level)),
            inferred_years_experience = COALESCE(NULLIF(inferred_years_experience, ''), VALUES(inferred_years_experience)),
            
            company_name = COALESCE(NULLIF(company_name, ''), VALUES(company_name)),
            company_domain = COALESCE(NULLIF(company_domain, ''), VALUES(company_domain)),
            company_phone = COALESCE(NULLIF(company_phone, ''), VALUES(company_phone)),
            company_address = COALESCE(NULLIF(company_address, ''), VALUES(company_address)),
            company_city = COALESCE(NULLIF(company_city, ''), VALUES(company_city)),
            company_state = COALESCE(NULLIF(company_state, ''), VALUES(company_state)),
            company_zip = COALESCE(NULLIF(company_zip, ''), VALUES(company_zip)),
            company_industry = COALESCE(NULLIF(company_industry, ''), VALUES(company_industry)),
            company_employee_count = COALESCE(NULLIF(company_employee_count, ''), VALUES(company_employee_count)),
            company_revenue = COALESCE(NULLIF(company_revenue, ''), VALUES(company_revenue)),
            company_sic = COALESCE(NULLIF(company_sic, ''), VALUES(company_sic)),
            company_naics = COALESCE(NULLIF(company_naics, ''), VALUES(company_naics)),
            company_description = COALESCE(NULLIF(company_description, ''), VALUES(company_description)),
            company_linkedin_url = COALESCE(NULLIF(company_linkedin_url, ''), VALUES(company_linkedin_url)),

            linkedin_url = COALESCE(NULLIF(linkedin_url, ''), VALUES(linkedin_url)),
            twitter_url = COALESCE(NULLIF(twitter_url, ''), VALUES(twitter_url)),
            facebook_url = COALESCE(NULLIF(facebook_url, ''), VALUES(facebook_url)),

            skiptrace_match_score = COALESCE(NULLIF(skiptrace_match_score, ''), VALUES(skiptrace_match_score)),
            skiptrace_name = COALESCE(NULLIF(skiptrace_name, ''), VALUES(skiptrace_name)),
            skiptrace_address = COALESCE(NULLIF(skiptrace_address, ''), VALUES(skiptrace_address)),
            skiptrace_city = COALESCE(NULLIF(skiptrace_city, ''), VALUES(skiptrace_city)),
            skiptrace_state = COALESCE(NULLIF(skiptrace_state, ''), VALUES(skiptrace_state)),
            skiptrace_zip = COALESCE(NULLIF(skiptrace_zip, ''), VALUES(skiptrace_zip)),
            skiptrace_landline_numbers = COALESCE(NULLIF(skiptrace_landline_numbers, ''), VALUES(skiptrace_landline_numbers)),
            skiptrace_wireless_numbers = COALESCE(NULLIF(skiptrace_wireless_numbers, ''), VALUES(skiptrace_wireless_numbers)),
            skiptrace_credit_rating = COALESCE(NULLIF(skiptrace_credit_rating, ''), VALUES(skiptrace_credit_rating)),
            skiptrace_dnc = COALESCE(NULLIF(skiptrace_dnc, ''), VALUES(skiptrace_dnc)),
            skiptrace_exact_age = COALESCE(NULLIF(skiptrace_exact_age, ''), VALUES(skiptrace_exact_age)),
            skiptrace_ethnic_code = COALESCE(NULLIF(skiptrace_ethnic_code, ''), VALUES(skiptrace_ethnic_code)),
            skiptrace_language_code = COALESCE(NULLIF(skiptrace_language_code, ''), VALUES(skiptrace_language_code)),
            skiptrace_ip = COALESCE(NULLIF(skiptrace_ip, ''), VALUES(skiptrace_ip)),
            skiptrace_b2b_address = COALESCE(NULLIF(skiptrace_b2b_address, ''), VALUES(skiptrace_b2b_address)),
            skiptrace_b2b_phone = COALESCE(NULLIF(skiptrace_b2b_phone, ''), VALUES(skiptrace_b2b_phone)),
            skiptrace_b2b_source = COALESCE(NULLIF(skiptrace_b2b_source, ''), VALUES(skiptrace_b2b_source)),
            skiptrace_b2b_website = COALESCE(NULLIF(skiptrace_b2b_website, ''), VALUES(skiptrace_b2b_website)),

            -- JSON HISTORY (Overwrite if new data exists)
            company_name_history = COALESCE(NULLIF(VALUES(company_name_history), ''), company_name_history),
            job_title_history = COALESCE(NULLIF(VALUES(job_title_history), ''), job_title_history),
            education_history = COALESCE(NULLIF(VALUES(education_history), ''), education_history),
            social_connections = COALESCE(NULLIF(VALUES(social_connections), ''), social_connections),
            skills = COALESCE(NULLIF(VALUES(skills), ''), skills),
            interests = COALESCE(NULLIF(VALUES(interests), ''), interests)
            ";

    try {
        $stmt = $mysqli->prepare($sql);
        
        if (!$stmt) {
            error_log("Visitor Upsert Prepare Error ($debug_context): " . $mysqli->error);
            return false;
        }

        if (!$stmt->bind_param($types, ...array_values($map))) {
            error_log("Visitor Upsert Bind Error ($debug_context): " . $stmt->error);
            $stmt->close();
            return false;
        }

        if (!$stmt->execute()) {
            error_log("Visitor Upsert Execute Error ($debug_context): " . $stmt->error);
            $stmt->close();
            return false;
        }

        $stmt->close();
        return true;

    } catch (Exception $e) {
        error_log("Visitor Upsert Exception ($debug_context): " . $e->getMessage());
        return false;
    }
}

// --- WRAPPER ---
function upsertVisitorFromEvent($mysqli, $event_data, $debug_context = "unknown") {
    return upsertVisitorV2($mysqli, $event_data, $debug_context);
}
?>
