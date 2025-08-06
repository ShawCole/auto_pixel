-- Fix for last_seen_at column to properly track visitor activity time
-- NOT the database update time

DROP TRIGGER IF EXISTS after_resolution_log_insert_visitor_update;

DELIMITER $$

CREATE TRIGGER after_resolution_log_insert_visitor_update 
AFTER INSERT ON superpixel_resolution_log 
FOR EACH ROW 
BEGIN
    IF NEW.uuid IS NOT NULL AND NEW.uuid != '' AND NEW.uuid != 'null' THEN
        IF EXISTS (SELECT 1 FROM superpixel_visitors WHERE uuid = NEW.uuid) THEN
            -- Update existing visitor with ALL new data fields
            UPDATE superpixel_visitors 
            SET
                -- Basic Information
                first_name = COALESCE(NULLIF(first_name, ''), NEW.first_name),
                last_name = COALESCE(NULLIF(last_name, ''), NEW.last_name),
                
                -- Personal Demographics
                personal_address = COALESCE(NULLIF(personal_address, ''), NEW.personal_address),
                personal_city = COALESCE(NULLIF(personal_city, ''), NEW.personal_city),
                personal_state = COALESCE(NULLIF(personal_state, ''), NEW.personal_state),
                personal_zip = COALESCE(NULLIF(personal_zip, ''), NEW.personal_zip),
                personal_zip4 = COALESCE(NULLIF(personal_zip4, ''), NEW.personal_zip4),
                age_range = COALESCE(NULLIF(age_range, ''), NEW.age_range),
                children = COALESCE(NULLIF(children, ''), NEW.children),
                gender = COALESCE(NULLIF(gender, ''), NEW.gender),
                homeowner = COALESCE(NULLIF(homeowner, ''), NEW.homeowner),
                married = COALESCE(NULLIF(married, ''), NEW.married),
                net_worth = COALESCE(NULLIF(net_worth, ''), NEW.net_worth),
                income_range = COALESCE(NULLIF(income_range, ''), NEW.income_range),
                
                -- Contact Information
                direct_number = COALESCE(NULLIF(direct_number, ''), NEW.direct_number),
                direct_number_dnc = COALESCE(NULLIF(direct_number_dnc, ''), NEW.direct_number_dnc),
                mobile_phone = COALESCE(NULLIF(mobile_phone, ''), NEW.mobile_phone),
                mobile_phone_dnc = COALESCE(NULLIF(mobile_phone_dnc, ''), NEW.mobile_phone_dnc),
                personal_phone = COALESCE(NULLIF(personal_phone, ''), NEW.personal_phone),
                personal_phone_dnc = COALESCE(NULLIF(personal_phone_dnc, ''), NEW.personal_phone_dnc),
                business_email = COALESCE(NULLIF(business_email, ''), NEW.business_email),
                personal_emails = COALESCE(NULLIF(personal_emails, ''), NEW.personal_emails),
                deep_verified_emails = COALESCE(NULLIF(deep_verified_emails, ''), NEW.deep_verified_emails),
                sha256_personal_email = COALESCE(NULLIF(sha256_personal_email, ''), NEW.sha256_personal_email),
                sha256_business_email = COALESCE(NULLIF(sha256_business_email, ''), NEW.sha256_business_email),
                hem_sha256 = COALESCE(NULLIF(hem_sha256, ''), NEW.hem_sha256),
                
                -- Professional Information
                job_title = COALESCE(NULLIF(job_title, ''), NEW.job_title),
                headline = COALESCE(NULLIF(headline, ''), NEW.headline),
                department = COALESCE(NULLIF(department, ''), NEW.department),
                seniority_level = COALESCE(NULLIF(seniority_level, ''), NEW.seniority_level),
                inferred_years_experience = COALESCE(NULLIF(inferred_years_experience, ''), NEW.inferred_years_experience),
                company_name_history = COALESCE(NULLIF(company_name_history, ''), NEW.company_name_history),
                job_title_history = COALESCE(NULLIF(job_title_history, ''), NEW.job_title_history),
                education_history = COALESCE(NULLIF(education_history, ''), NEW.education_history),
                
                -- Company Information
                company_address = COALESCE(NULLIF(company_address, ''), NEW.company_address),
                company_description = COALESCE(NULLIF(company_description, ''), NEW.company_description),
                company_domain = COALESCE(NULLIF(company_domain, ''), NEW.company_domain),
                company_employee_count = COALESCE(NULLIF(company_employee_count, ''), NEW.company_employee_count),
                company_linkedin_url = COALESCE(NULLIF(company_linkedin_url, ''), NEW.company_linkedin_url),
                company_name = COALESCE(NULLIF(company_name, ''), NEW.company_name),
                company_phone = COALESCE(NULLIF(company_phone, ''), NEW.company_phone),
                company_revenue = COALESCE(NULLIF(company_revenue, ''), NEW.company_revenue),
                company_sic = COALESCE(NULLIF(company_sic, ''), NEW.company_sic),
                company_naics = COALESCE(NULLIF(company_naics, ''), NEW.company_naics),
                company_city = COALESCE(NULLIF(company_city, ''), NEW.company_city),
                company_state = COALESCE(NULLIF(company_state, ''), NEW.company_state),
                company_zip = COALESCE(NULLIF(company_zip, ''), NEW.company_zip),
                company_industry = COALESCE(NULLIF(company_industry, ''), NEW.company_industry),
                
                -- Social Media
                linkedin_url = COALESCE(NULLIF(linkedin_url, ''), NEW.linkedin_url),
                twitter_url = COALESCE(NULLIF(twitter_url, ''), NEW.twitter_url),
                facebook_url = COALESCE(NULLIF(facebook_url, ''), NEW.facebook_url),
                social_connections = COALESCE(NULLIF(social_connections, ''), NEW.social_connections),
                skills = COALESCE(NULLIF(skills, ''), NEW.skills),
                interests = COALESCE(NULLIF(interests, ''), NEW.interests),
                
                -- Skiptrace Information
                skiptrace_match_score = COALESCE(NULLIF(skiptrace_match_score, ''), NEW.skiptrace_match_score),
                skiptrace_name = COALESCE(NULLIF(skiptrace_name, ''), NEW.skiptrace_name),
                skiptrace_address = COALESCE(NULLIF(skiptrace_address, ''), NEW.skiptrace_address),
                skiptrace_city = COALESCE(NULLIF(skiptrace_city, ''), NEW.skiptrace_city),
                skiptrace_state = COALESCE(NULLIF(skiptrace_state, ''), NEW.skiptrace_state),
                skiptrace_zip = COALESCE(NULLIF(skiptrace_zip, ''), NEW.skiptrace_zip),
                skiptrace_landline_numbers = COALESCE(NULLIF(skiptrace_landline_numbers, ''), NEW.skiptrace_landline_numbers),
                skiptrace_wireless_numbers = COALESCE(NULLIF(skiptrace_wireless_numbers, ''), NEW.skiptrace_wireless_numbers),
                skiptrace_credit_rating = COALESCE(NULLIF(skiptrace_credit_rating, ''), NEW.skiptrace_credit_rating),
                skiptrace_dnc = COALESCE(NULLIF(skiptrace_dnc, ''), NEW.skiptrace_dnc),
                skiptrace_exact_age = COALESCE(NULLIF(skiptrace_exact_age, ''), NEW.skiptrace_exact_age),
                skiptrace_ethnic_code = COALESCE(NULLIF(skiptrace_ethnic_code, ''), NEW.skiptrace_ethnic_code),
                skiptrace_language_code = COALESCE(NULLIF(skiptrace_language_code, ''), NEW.skiptrace_language_code),
                skiptrace_ip = COALESCE(NULLIF(skiptrace_ip, ''), NEW.skiptrace_ip),
                skiptrace_b2b_address = COALESCE(NULLIF(skiptrace_b2b_address, ''), NEW.skiptrace_b2b_address),
                skiptrace_b2b_phone = COALESCE(NULLIF(skiptrace_b2b_phone, ''), NEW.skiptrace_b2b_phone),
                skiptrace_b2b_source = COALESCE(NULLIF(skiptrace_b2b_source, ''), NEW.skiptrace_b2b_source),
                skiptrace_b2b_website = COALESCE(NULLIF(skiptrace_b2b_website, ''), NEW.skiptrace_b2b_website),
                
                -- CRITICAL: License Fields
                npn = COALESCE(NULLIF(npn, ''), NEW.npn),
                crd = COALESCE(NULLIF(crd, ''), NEW.crd),
                
                -- Event Information (always update with latest)
                url = CASE WHEN NEW.url IS NOT NULL AND NEW.url != '' THEN NEW.url ELSE url END,
                element = CASE WHEN NEW.element IS NOT NULL AND NEW.element != '' THEN NEW.element ELSE element END,
                percentage = CASE 
                    WHEN NEW.percentage IS NOT NULL AND NEW.percentage != '' THEN CAST(NEW.percentage AS SIGNED)
                    ELSE percentage 
                END,
                referrer = CASE WHEN NEW.referrer IS NOT NULL AND NEW.referrer != '' THEN NEW.referrer ELSE referrer END,
                event_timestamp = CASE WHEN NEW.event_timestamp IS NOT NULL AND NEW.event_timestamp != '' THEN NEW.event_timestamp ELSE event_timestamp END,
                event_type = CASE WHEN NEW.event_type IS NOT NULL AND NEW.event_type != '' THEN NEW.event_type ELSE event_type END,
                
                -- Update counters and timestamps
                event_count = event_count + 1,
                -- FIX: Use the actual event timestamp, not the database update time
                last_seen_at = CASE 
                    WHEN NEW.event_timestamp IS NOT NULL AND NEW.event_timestamp != '' 
                    THEN STR_TO_DATE(NEW.event_timestamp, '%Y-%m-%dT%H:%i:%sZ')
                    ELSE last_seen_at 
                END
            WHERE uuid = NEW.uuid;
        ELSE
            -- Insert new visitor with ALL fields
            INSERT INTO superpixel_visitors (
                uuid, first_name, last_name,
                personal_address, personal_city, personal_state, personal_zip, personal_zip4,
                age_range, children, gender, homeowner, married, net_worth, income_range,
                direct_number, direct_number_dnc, mobile_phone, mobile_phone_dnc,
                personal_phone, personal_phone_dnc, business_email, personal_emails,
                deep_verified_emails, sha256_personal_email, sha256_business_email, hem_sha256,
                job_title, headline, department, seniority_level, inferred_years_experience,
                company_name_history, job_title_history, education_history,
                company_address, company_description, company_domain, company_employee_count,
                company_linkedin_url, company_name, company_phone, company_revenue,
                company_sic, company_naics, company_city, company_state, company_zip, company_industry,
                linkedin_url, twitter_url, facebook_url, social_connections, skills, interests,
                skiptrace_match_score, skiptrace_name, skiptrace_address, skiptrace_city,
                skiptrace_state, skiptrace_zip, skiptrace_landline_numbers, skiptrace_wireless_numbers,
                skiptrace_credit_rating, skiptrace_dnc, skiptrace_exact_age, skiptrace_ethnic_code,
                skiptrace_language_code, skiptrace_ip, skiptrace_b2b_address, skiptrace_b2b_phone,
                skiptrace_b2b_source, skiptrace_b2b_website,
                npn, crd,
                url, element, percentage, referrer, event_timestamp, event_type,
                event_count, first_seen_at, last_seen_at
            ) VALUES (
                NEW.uuid, NEW.first_name, NEW.last_name,
                NEW.personal_address, NEW.personal_city, NEW.personal_state, NEW.personal_zip, NEW.personal_zip4,
                NEW.age_range, NEW.children, NEW.gender, NEW.homeowner, NEW.married, NEW.net_worth, NEW.income_range,
                NEW.direct_number, NEW.direct_number_dnc, NEW.mobile_phone, NEW.mobile_phone_dnc,
                NEW.personal_phone, NEW.personal_phone_dnc, NEW.business_email, NEW.personal_emails,
                NEW.deep_verified_emails, NEW.sha256_personal_email, NEW.sha256_business_email, NEW.hem_sha256,
                NEW.job_title, NEW.headline, NEW.department, NEW.seniority_level, NEW.inferred_years_experience,
                NEW.company_name_history, NEW.job_title_history, NEW.education_history,
                NEW.company_address, NEW.company_description, NEW.company_domain, NEW.company_employee_count,
                NEW.company_linkedin_url, NEW.company_name, NEW.company_phone, NEW.company_revenue,
                NEW.company_sic, NEW.company_naics, NEW.company_city, NEW.company_state, NEW.company_zip, NEW.company_industry,
                NEW.linkedin_url, NEW.twitter_url, NEW.facebook_url, NEW.social_connections, NEW.skills, NEW.interests,
                NEW.skiptrace_match_score, NEW.skiptrace_name, NEW.skiptrace_address, NEW.skiptrace_city,
                NEW.skiptrace_state, NEW.skiptrace_zip, NEW.skiptrace_landline_numbers, NEW.skiptrace_wireless_numbers,
                NEW.skiptrace_credit_rating, NEW.skiptrace_dnc, NEW.skiptrace_exact_age, NEW.skiptrace_ethnic_code,
                NEW.skiptrace_language_code, NEW.skiptrace_ip, NEW.skiptrace_b2b_address, NEW.skiptrace_b2b_phone,
                NEW.skiptrace_b2b_source, NEW.skiptrace_b2b_website,
                NEW.npn, NEW.crd,
                NEW.url, NEW.element, 
                CASE WHEN NEW.percentage IS NOT NULL AND NEW.percentage != '' THEN CAST(NEW.percentage AS SIGNED) ELSE NULL END,
                NEW.referrer, NEW.event_timestamp, NEW.event_type,
                1, 
                -- FIX: Use event timestamp for first_seen_at
                CASE 
                    WHEN NEW.event_timestamp IS NOT NULL AND NEW.event_timestamp != '' 
                    THEN STR_TO_DATE(NEW.event_timestamp, '%Y-%m-%dT%H:%i:%sZ')
                    ELSE CURRENT_TIMESTAMP 
                END,
                -- FIX: Use event timestamp for last_seen_at
                CASE 
                    WHEN NEW.event_timestamp IS NOT NULL AND NEW.event_timestamp != '' 
                    THEN STR_TO_DATE(NEW.event_timestamp, '%Y-%m-%dT%H:%i:%sZ')
                    ELSE CURRENT_TIMESTAMP 
                END
            );
        END IF;
    END IF;
END$$

DELIMITER ;

-- Also fix existing records where last_seen_at is wrong
-- This will update last_seen_at to match the most recent event_timestamp for each visitor
UPDATE superpixel_visitors v
JOIN (
    SELECT 
        uuid,
        MAX(
            CASE 
                WHEN event_timestamp REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}' 
                THEN STR_TO_DATE(event_timestamp, '%Y-%m-%dT%H:%i:%sZ')
                ELSE NULL
            END
        ) as latest_event
    FROM superpixel_resolution_log
    WHERE event_timestamp IS NOT NULL 
        AND event_timestamp != ''
        AND event_timestamp REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
    GROUP BY uuid
    HAVING latest_event IS NOT NULL
) latest ON v.uuid = latest.uuid
SET v.last_seen_at = latest.latest_event
WHERE latest.latest_event IS NOT NULL;

SELECT 'Trigger and timestamps fixed successfully!' as status; 