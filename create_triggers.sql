-- Trigger for superpixel_resolution_log table
DELIMITER $$

DROP TRIGGER IF EXISTS before_resolution_log_insert$$

CREATE TRIGGER before_resolution_log_insert
BEFORE INSERT ON superpixel_resolution_log
FOR EACH ROW
BEGIN
    DECLARE vNPN VARCHAR(255);
    DECLARE vCRD VARCHAR(255);

    -- Fetch values from accupoint_solutions database
    SELECT NPN, CRD
    INTO vNPN, vCRD
    FROM accupoint_solutions.hash_emails
    WHERE hash256 = NEW.hem_sha256
    LIMIT 1;

    -- Assign to NEW values
    SET NEW.npn = vNPN;
    SET NEW.crd = vCRD;
END$$

DELIMITER ;

-- Trigger for superpixel_visitors table
DELIMITER $$

DROP TRIGGER IF EXISTS before_visitors_insert$$

CREATE TRIGGER before_visitors_insert
BEFORE INSERT ON superpixel_visitors
FOR EACH ROW
BEGIN
    DECLARE vNPN VARCHAR(255);
    DECLARE vCRD VARCHAR(255);

    -- Fetch values from accupoint_solutions database
    SELECT NPN, CRD
    INTO vNPN, vCRD
    FROM accupoint_solutions.hash_emails
    WHERE hash256 = NEW.hem_sha256
    LIMIT 1;

    -- Assign to NEW values
    SET NEW.npn = vNPN;
    SET NEW.crd = vCRD;
END$$

DELIMITER ;

-- Update trigger for superpixel_visitors table
DELIMITER $$

DROP TRIGGER IF EXISTS before_visitors_update$$

CREATE TRIGGER before_visitors_update
BEFORE UPDATE ON superpixel_visitors
FOR EACH ROW
BEGIN
    DECLARE vNPN VARCHAR(255);
    DECLARE vCRD VARCHAR(255);

    -- Only update if NPN/CRD are null and hem_sha256 has changed or is being set
    IF (NEW.npn IS NULL OR NEW.crd IS NULL) AND NEW.hem_sha256 IS NOT NULL THEN
        -- Fetch values from accupoint_solutions database
        SELECT NPN, CRD
        INTO vNPN, vCRD
        FROM accupoint_solutions.hash_emails
        WHERE hash256 = NEW.hem_sha256
        LIMIT 1;

        -- Assign to NEW values if found
        IF vNPN IS NOT NULL THEN
            SET NEW.npn = vNPN;
        END IF;
        IF vCRD IS NOT NULL THEN
            SET NEW.crd = vCRD;
        END IF;
    END IF;
END$$

DELIMITER ; 