-- This file contains the necessary SQL to set up procedures and triggers for a new client database.
-- It is executed by the backend provisioning script after tables are created.

-- COMMAND_SEPARATOR --

-- Drop the email parsing procedure if it exists
DROP PROCEDURE IF EXISTS parse_visitor_emails;

-- COMMAND_SEPARATOR --

-- Recreate the email parsing procedure
CREATE PROCEDURE parse_visitor_emails(
    IN p_uuid VARCHAR(100),
    IN p_email_string TEXT,
    IN p_email_type ENUM('personal', 'business', 'deep_verified'),
    IN p_source_column VARCHAR(50)
)
proc_label: BEGIN -- Added a label for LEAVE
    DECLARE email_item VARCHAR(255);
    DECLARE remaining_string TEXT;
    DECLARE comma_pos INT;

    -- Exit early if input is NULL or empty
    IF p_email_string IS NULL OR TRIM(p_email_string) = '' THEN
        LEAVE proc_label; -- Use LEAVE with the label
    END IF;

    SET remaining_string = TRIM(p_email_string);

    WHILE remaining_string IS NOT NULL AND LENGTH(remaining_string) > 0 DO
        SET comma_pos = LOCATE(',', remaining_string);
        IF comma_pos = 0 THEN
            SET email_item = TRIM(remaining_string);
            SET remaining_string = ''; -- End loop
        ELSE
            SET email_item = TRIM(SUBSTRING(remaining_string, 1, comma_pos - 1));
            SET remaining_string = TRIM(SUBSTRING(remaining_string, comma_pos + 1));
        END IF;

        -- Validate and Insert (corrected regex)
        IF email_item REGEXP '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}$' THEN
            INSERT IGNORE INTO superpixel_emails (uuid, email, email_type, source_column)
            VALUES (p_uuid, email_item, p_email_type, p_source_column);
        END IF;
    END WHILE;
END; -- NOTE: Use standard semicolon here

-- COMMAND_SEPARATOR --

-- Drop the main visitor hydration trigger if it exists
DROP TRIGGER IF EXISTS after_resolution_log_insert_visitor_update;

-- COMMAND_SEPARATOR --

-- Recreate the main visitor hydration trigger
CREATE TRIGGER after_resolution_log_insert_visitor_update
AFTER INSERT ON superpixel_resolution_log
FOR EACH ROW
BEGIN
  IF NEW.uuid IS NOT NULL AND NEW.uuid <> '' AND NEW.uuid <> 'null' THEN
    IF EXISTS (SELECT 1 FROM superpixel_visitors WHERE uuid = NEW.uuid) THEN
      UPDATE superpixel_visitors
      SET
        first_name = COALESCE(NULLIF(first_name, ''), NEW.first_name),
        last_name  = COALESCE(NULLIF(last_name,  ''), NEW.last_name),
        business_email = COALESCE(NULLIF(business_email, ''), NEW.business_email),
        personal_emails = COALESCE(NULLIF(personal_emails, ''), NEW.personal_emails),
        deep_verified_emails = COALESCE(NULLIF(deep_verified_emails, ''), NEW.deep_verified_emails),
        sha256_personal_email = COALESCE(NULLIF(sha256_personal_email, ''), NEW.sha256_personal_email),
        sha256_business_email = COALESCE(NULLIF(sha256_business_email, ''), NEW.sha256_business_email),
        direct_number = COALESCE(NULLIF(direct_number, ''), NEW.direct_number),
        direct_number_dnc = COALESCE(NULLIF(direct_number_dnc, ''), NEW.direct_number_dnc),
        mobile_phone = COALESCE(NULLIF(mobile_phone, ''), NEW.mobile_phone),
        mobile_phone_dnc = COALESCE(NULLIF(mobile_phone_dnc, ''), NEW.mobile_phone_dnc),
        personal_phone = COALESCE(NULLIF(personal_phone, ''), NEW.personal_phone),
        personal_phone_dnc = COALESCE(NULLIF(personal_phone_dnc, ''), NEW.personal_phone_dnc),
        valid_phones = COALESCE(NULLIF(valid_phones, ''), NEW.valid_phones),
        personal_address = COALESCE(NULLIF(personal_address, ''), NEW.personal_address),
        personal_city = COALESCE(NULLIF(personal_city, ''), NEW.personal_city),
        personal_state = COALESCE(NULLIF(personal_state, ''), NEW.personal_state),
        personal_zip = COALESCE(NULLIF(personal_zip, ''), NEW.personal_zip),
        personal_zip4 = COALESCE(NULLIF(personal_zip4, ''), NEW.personal_zip4),
        age_range = COALESCE(NULLIF(age_range, ''), NEW.age_range),
        children  = COALESCE(NULLIF(children,  ''), NEW.children),
        gender    = COALESCE(NULLIF(gender,    ''), NEW.gender),
        homeowner = COALESCE(NULLIF(homeowner, ''), NEW.homeowner),
        married   = COALESCE(NULLIF(married,   ''), NEW.married),
        net_worth = COALESCE(NULLIF(net_worth, ''), NEW.net_worth),
        income_range = COALESCE(NULLIF(income_range, ''), NEW.income_range),
        job_title = COALESCE(NULLIF(job_title, ''), NEW.job_title),
        headline  = COALESCE(NULLIF(headline,  ''), NEW.headline),
        department = COALESCE(NULLIF(department, ''), NEW.department),
        seniority_level = COALESCE(NULLIF(seniority_level, ''), NEW.seniority_level),
        inferred_years_experience = COALESCE(NULLIF(inferred_years_experience, ''), NEW.inferred_years_experience),
        company_name_history = COALESCE(NULLIF(company_name_history, ''), NEW.company_name_history),
        job_title_history = COALESCE(NULLIF(job_title_history, ''), NEW.job_title_history),
        education_history = COALESCE(NULLIF(education_history, ''), NEW.education_history),
        company_name = COALESCE(NULLIF(company_name, ''), NEW.company_name),
        company_domain = COALESCE(NULLIF(company_domain, ''), NEW.company_domain),
        company_phone = COALESCE(NULLIF(company_phone, ''), NEW.company_phone),
        company_city  = COALESCE(NULLIF(company_city,  ''), NEW.company_city),
        company_state = COALESCE(NULLIF(company_state, ''), NEW.company_state),
        company_zip   = COALESCE(NULLIF(company_zip,   ''), NEW.company_zip),
        company_address = COALESCE(NULLIF(company_address, ''), NEW.company_address),
        company_description = COALESCE(NULLIF(company_description, ''), NEW.company_description),
        company_employee_count = COALESCE(NULLIF(company_employee_count, ''), NEW.company_employee_count),
        company_revenue = COALESCE(NULLIF(company_revenue, ''), NEW.company_revenue),
        company_sic = COALESCE(NULLIF(company_sic, ''), NEW.company_sic),
        company_naics = COALESCE(NULLIF(company_naics, ''), NEW.company_naics),
        company_industry = COALESCE(NULLIF(company_industry, ''), NEW.company_industry),
        company_linkedin_url = COALESCE(NULLIF(company_linkedin_url, ''), NEW.company_linkedin_url),
        linkedin_url = COALESCE(NULLIF(linkedin_url, ''), NEW.linkedin_url),
        twitter_url  = COALESCE(NULLIF(twitter_url,  ''), NEW.twitter_url),
        facebook_url = COALESCE(NULLIF(facebook_url, ''), NEW.facebook_url),
        social_connections = COALESCE(NULLIF(social_connections, ''), NEW.social_connections),
        skills = COALESCE(NULLIF(skills, ''), NEW.skills),
        interests = COALESCE(NULLIF(interests, ''), NEW.interests),
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
        hem_sha256 = COALESCE(NULLIF(hem_sha256, ''), NEW.hem_sha256),
        npn = COALESCE(NULLIF(npn, ''), NEW.npn),
        crd = COALESCE(NULLIF(crd, ''), NEW.crd),
        ip_address = CASE WHEN NEW.ip_address IS NOT NULL AND NEW.ip_address <> '' THEN NEW.ip_address ELSE ip_address END,
        pixel_id = CASE WHEN NEW.pixel_id IS NOT NULL AND NEW.pixel_id <> '' THEN NEW.pixel_id ELSE pixel_id END,
        activity_start_date = CASE WHEN NEW.activity_start_date IS NOT NULL AND NEW.activity_start_date <> '' THEN NEW.activity_start_date ELSE activity_start_date END,
        activity_end_date = CASE WHEN NEW.activity_end_date IS NOT NULL AND NEW.activity_end_date <> '' THEN NEW.activity_end_date ELSE activity_end_date END,
        referrer_url = CASE WHEN NEW.referrer_url IS NOT NULL AND NEW.referrer_url <> '' THEN NEW.referrer_url ELSE referrer_url END,
        `timestamp` = CASE WHEN NEW.`timestamp` IS NOT NULL AND NEW.`timestamp` <> '' THEN NEW.`timestamp` ELSE `timestamp` END,
        title = CASE WHEN NEW.title IS NOT NULL AND NEW.title <> '' THEN NEW.title ELSE title END,
        url = CASE WHEN NEW.url IS NOT NULL AND NEW.url <> '' THEN NEW.url ELSE url END,
        element = CASE WHEN NEW.element IS NOT NULL AND NEW.element <> '' THEN NEW.element ELSE element END,
        percentage = CASE WHEN NEW.percentage IS NOT NULL AND NEW.percentage <> '' THEN CAST(NEW.percentage AS SIGNED) ELSE percentage END,
        referrer = CASE WHEN NEW.referrer IS NOT NULL AND NEW.referrer <> '' THEN NEW.referrer ELSE referrer END,
        event_timestamp = CASE WHEN NEW.event_timestamp IS NOT NULL AND NEW.event_timestamp <> '' THEN NEW.event_timestamp ELSE event_timestamp END,
        event_type = CASE WHEN NEW.event_type IS NOT NULL AND NEW.event_type <> '' THEN NEW.event_type ELSE event_type END,
        event_count = event_count + 1,
        last_seen_at = CURRENT_TIMESTAMP
      WHERE
        uuid = NEW.uuid;
    ELSE
      INSERT INTO superpixel_visitors (
        uuid, first_name, last_name,
        personal_address, personal_city, personal_state, personal_zip, personal_zip4,
        age_range, children, gender, homeowner, married, net_worth, income_range,
        direct_number, direct_number_dnc, mobile_phone, mobile_phone_dnc, personal_phone, personal_phone_dnc,
        business_email, personal_emails, deep_verified_emails, sha256_personal_email, sha256_business_email,
        hem_sha256, job_title, headline, department, seniority_level, inferred_years_experience,
        company_name_history, job_title_history, education_history,
        company_address, company_description, company_domain, company_employee_count, company_linkedin_url,
        company_name, company_phone, company_revenue, company_sic, company_naics, company_city, company_state, company_zip, company_industry,
        linkedin_url, twitter_url, facebook_url, social_connections, skills, interests,
        skiptrace_match_score, skiptrace_name, skiptrace_address, skiptrace_city, skiptrace_state, skiptrace_zip,
        skiptrace_landline_numbers, skiptrace_wireless_numbers, skiptrace_credit_rating, skiptrace_dnc, skiptrace_exact_age, skiptrace_ethnic_code, skiptrace_language_code, skiptrace_ip,
        skiptrace_b2b_address, skiptrace_b2b_phone, skiptrace_b2b_source, skiptrace_b2b_website, valid_phones,
        url, element, percentage, referrer, event_timestamp, event_type,
        npn, crd,
        ip_address, pixel_id, activity_start_date, activity_end_date, referrer_url, `timestamp`, title,
        event_count, first_seen_at, last_seen_at
      ) VALUES (
        NEW.uuid, NEW.first_name, NEW.last_name,
        NEW.personal_address, NEW.personal_city, NEW.personal_state, NEW.personal_zip, NEW.personal_zip4,
        NEW.age_range, NEW.children, NEW.gender, NEW.homeowner, NEW.married, NEW.net_worth, NEW.income_range,
        NEW.direct_number, NEW.direct_number_dnc, NEW.mobile_phone, NEW.mobile_phone_dnc, NEW.personal_phone, NEW.personal_phone_dnc,
        NEW.business_email, NEW.personal_emails, NEW.deep_verified_emails, NEW.sha256_personal_email, NEW.sha256_business_email,
        NEW.hem_sha256, NEW.job_title, NEW.headline, NEW.department, NEW.seniority_level, NEW.inferred_years_experience,
        NEW.company_name_history, NEW.job_title_history, NEW.education_history,
        NEW.company_address, NEW.company_description, NEW.company_domain, NEW.company_employee_count, NEW.company_linkedin_url,
        NEW.company_name, NEW.company_phone, NEW.company_revenue, NEW.company_sic, NEW.company_naics, NEW.company_city, NEW.company_state, NEW.company_zip, NEW.company_industry,
        NEW.linkedin_url, NEW.twitter_url, NEW.facebook_url, NEW.social_connections, NEW.skills, NEW.interests,
        NEW.skiptrace_match_score, NEW.skiptrace_name, NEW.skiptrace_address, NEW.skiptrace_city, NEW.skiptrace_state, NEW.skiptrace_zip,
        NEW.skiptrace_landline_numbers, NEW.skiptrace_wireless_numbers, NEW.skiptrace_credit_rating, NEW.skiptrace_dnc, NEW.skiptrace_exact_age, NEW.skiptrace_ethnic_code, NEW.skiptrace_language_code, NEW.skiptrace_ip,
        NEW.skiptrace_b2b_address, NEW.skiptrace_b2b_phone, NEW.skiptrace_b2b_source, NEW.skiptrace_b2b_website, NEW.valid_phones,
        NEW.url, NEW.element, CASE WHEN NEW.percentage IS NOT NULL AND NEW.percentage <> '' THEN CAST(NEW.percentage AS SIGNED) ELSE NULL END, NEW.referrer, NEW.event_timestamp, NEW.event_type,
        NEW.npn, NEW.crd,
        NEW.ip_address, NEW.pixel_id, NEW.activity_start_date, NEW.activity_end_date, NEW.referrer_url, NEW.`timestamp`, NEW.title,
        1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
      );
    END IF;
  END IF;
END; -- NOTE: Use standard semicolon here

-- COMMAND_SEPARATOR --