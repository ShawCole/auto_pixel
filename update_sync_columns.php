<?php
// Updated sync functions with all new columns

function syncVisitorsToSheet($mysqli, $clientName, $sheetId, $service) {
    global $VISITORS_LIMIT;
    echo "Syncing visitors for $clientName (limit: $VISITORS_LIMIT)...\n";
    
    // Get visitor data with all new columns
    $sql = "SELECT 
            uuid, 
            first_name, 
            last_name, 
            company_name, 
            job_title, 
            personal_emails, 
            mobile_phone,
            personal_address,
            personal_city, 
            personal_state,
            personal_zip,
            first_seen_at, 
            last_seen_at, 
            event_count,
            last_visited_url,
            last_element,
            last_percentage,
            last_referrer,
            last_timestamp,
            last_event,
            npn,
            crd
        FROM superpixel_visitors 
        WHERE uuid IS NOT NULL 
        ORDER BY last_seen_at DESC, event_count DESC 
        LIMIT $VISITORS_LIMIT";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        echo "Error querying visitors: " . $mysqli->error . "\n";
        return false;
    }
    
    $visitors = [];
    while ($row = $result->fetch_assoc()) {
        $visitors[] = [
            $row['uuid'] ?? '',
            $row['first_name'] ?? '',
            $row['last_name'] ?? '',
            $row['company_name'] ?? '',
            $row['job_title'] ?? '',
            $row['personal_emails'] ?? '',
            $row['mobile_phone'] ?? '',
            $row['personal_address'] ?? '',
            $row['personal_city'] ?? '',
            $row['personal_state'] ?? '',
            $row['personal_zip'] ?? '',
            $row['first_seen_at'] ?? '',
            $row['last_seen_at'] ?? '',
            $row['event_count'] ?? 0,
            $row['last_visited_url'] ?? '',
            $row['last_element'] ?? '',
            $row['last_percentage'] ?? '',
            $row['last_referrer'] ?? '',
            $row['last_timestamp'] ?? '',
            $row['last_event'] ?? '',
            $row['npn'] ?? '',
            $row['crd'] ?? ''
        ];
    }
    
    if (empty($visitors)) {
        echo "No visitor data to sync\n";
        return true;
    }
    
    // Updated headers with all new columns
    $headers = [
        'UUID', 
        'First Name', 
        'Last Name', 
        'Company', 
        'Job Title', 
        'Emails', 
        'Phone',
        'Personal Address',
        'City', 
        'State',
        'Zip',
        'First Seen', 
        'Last Seen', 
        'Event Count',
        'Last Visited URL',
        'Last Element',
        'Last Percentage',
        'Last Referrer',
        'Last Timestamp',
        'Last Event',
        'NPN',
        'CRD'
    ];
    
    $allData = array_merge([$headers], $visitors);
    
    // Update range to include all columns (A to V)
    $range = 'Visitors!A1:V' . count($allData);
    $body = new ValueRange(['values' => $allData]);
    
    try {
        $service->spreadsheets_values->update($sheetId, $range, $body, ['valueInputOption' => 'RAW']);
        echo "Updated " . count($visitors) . " visitor records (max: $VISITORS_LIMIT)\n";
        return true;
    } catch (Exception $e) {
        echo "Error updating visitors: " . $e->getMessage() . "\n";
        return false;
    }
}

function syncEventsToSheet($mysqli, $clientName, $sheetId, $service) {
    global $EVENTS_LIMIT;
    echo "Syncing events for $clientName (limit: $EVENTS_LIMIT)...\n";
    
    // Get recent events with new columns
    $sql = "SELECT 
            event_timestamp, 
            event_type,
            visited_url as url,
            elements,
            referrer,
            ip_address, 
            uuid, 
            first_name, 
            last_name, 
            company_name, 
            job_title, 
            personal_emails, 
            mobile_phone, 
            personal_city, 
            personal_state,
            hem_sha256
        FROM superpixel_resolution_log 
        WHERE event_timestamp IS NOT NULL 
        ORDER BY event_timestamp DESC 
        LIMIT $EVENTS_LIMIT";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        echo "Error querying events: " . $mysqli->error . "\n";
        return false;
    }
    
    $events = [];
    while ($row = $result->fetch_assoc()) {
        $events[] = [
            $row['event_timestamp'] ?? '',
            $row['event_type'] ?? '',
            $row['url'] ?? '',
            $row['elements'] ?? '',
            $row['referrer'] ?? '',
            $row['ip_address'] ?? '',
            $row['uuid'] ?? '',
            $row['first_name'] ?? '',
            $row['last_name'] ?? '',
            $row['company_name'] ?? '',
            $row['job_title'] ?? '',
            $row['personal_emails'] ?? '',
            $row['mobile_phone'] ?? '',
            $row['personal_city'] ?? '',
            $row['personal_state'] ?? '',
            $row['hem_sha256'] ?? ''
        ];
    }
    
    if (empty($events)) {
        echo "No new event data to sync\n";
        return true;
    }
    
    // Updated headers with new columns
    $headers = [
        'Timestamp', 
        'Event Type',
        'URL',
        'Elements',
        'Referrer',
        'IP Address', 
        'UUID', 
        'First Name', 
        'Last Name', 
        'Company', 
        'Job Title', 
        'Emails', 
        'Phone', 
        'City', 
        'State',
        'HemSha256'
    ];
    
    $allData = array_merge([$headers], $events);
    
    // Update range to include all columns (A to P)
    $range = 'Events!A1:P' . count($allData);
    $body = new ValueRange(['values' => $allData]);
    
    try {
        $service->spreadsheets_values->update($sheetId, $range, $body, ['valueInputOption' => 'RAW']);
        echo "Full refresh: Updated " . count($events) . " event records\n";
        return true;
    } catch (Exception $e) {
        echo "Error updating events: " . $e->getMessage() . "\n";
        return false;
    }
}
?> 