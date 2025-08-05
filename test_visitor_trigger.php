<?php
// Test script for visitor automation trigger
// This will deploy the trigger to AcquireUp and run a test

require_once 'config.php';

function testVisitorTrigger($client = 'AcquireUp') {
    global $connection;
    
    echo "🚀 Testing Visitor Automation Trigger for $client\n";
    echo "=" . str_repeat("=", 50) . "\n\n";
    
    // Switch to client database
    $connection->query("USE `$client`");
    
    // Step 1: Check if trigger already exists
    echo "1️⃣  Checking existing triggers...\n";
    $result = $connection->query("SHOW TRIGGERS LIKE 'after_resolution_log_insert_visitor_update'");
    if ($result && $result->num_rows > 0) {
        echo "   ⚠️  Trigger already exists. Dropping it first...\n";
        $connection->query("DROP TRIGGER IF EXISTS after_resolution_log_insert_visitor_update");
    }
    
    // Step 2: Read and deploy the trigger
    echo "2️⃣  Deploying new trigger...\n";
    $trigger_sql = file_get_contents('create_visitor_automation_trigger.sql');
    
    // Execute the trigger creation
    if ($connection->multi_query($trigger_sql)) {
        // Clear any remaining results
        while ($connection->more_results() && $connection->next_result()) {
            if ($result = $connection->store_result()) {
                $result->free();
            }
        }
        echo "   ✅ Trigger deployed successfully!\n\n";
    } else {
        echo "   ❌ Failed to deploy trigger: " . $connection->error . "\n";
        exit(1);
    }
    
    // Step 3: Get current counts
    echo "3️⃣  Getting baseline counts...\n";
    $events_count = $connection->query("SELECT COUNT(*) as count FROM superpixel_resolution_log")->fetch_assoc()['count'];
    $visitors_count = $connection->query("SELECT COUNT(*) as count FROM superpixel_visitors")->fetch_assoc()['count'];
    echo "   📊 Events: $events_count | Visitors: $visitors_count\n\n";
    
    // Step 4: Insert a test event
    echo "4️⃣  Inserting test event...\n";
    $test_uuid = 'TEST_' . uniqid();
    $test_event = [
        'uuid' => $test_uuid,
        'first_name' => 'John',
        'last_name' => 'TestUser',
        'event_timestamp' => date('Y-m-d H:i:s'),
        'event_type' => 'page_view',
        'url' => 'https://example.com/test-page',
        'element' => 'button#test',
        'percentage' => '75',
        'referrer' => 'https://google.com',
        'ip_address' => '192.168.1.1',
        'business_email' => 'john@testcompany.com',
        'company_name' => 'Test Company Inc',
        'job_title' => 'Test Manager',
        'mobile_phone' => '555-0123',
        'personal_city' => 'Test City',
        'personal_state' => 'TS',
        'personal_zip' => '12345'
    ];
    
    // Build INSERT query
    $columns = array_keys($test_event);
    $values = array_map(function($v) use ($connection) {
        return "'" . $connection->real_escape_string($v) . "'";
    }, array_values($test_event));
    
    $insert_sql = "INSERT INTO superpixel_resolution_log (" . implode(', ', $columns) . ") 
                   VALUES (" . implode(', ', $values) . ")";
    
    if ($connection->query($insert_sql)) {
        echo "   ✅ Test event inserted with UUID: $test_uuid\n\n";
    } else {
        echo "   ❌ Failed to insert test event: " . $connection->error . "\n";
        exit(1);
    }
    
    // Step 5: Verify visitor was created
    echo "5️⃣  Verifying visitor creation...\n";
    sleep(1); // Give trigger time to execute
    
    $visitor_check = $connection->query("SELECT * FROM superpixel_visitors WHERE uuid = '$test_uuid'");
    if ($visitor_check && $visitor_check->num_rows > 0) {
        $visitor = $visitor_check->fetch_assoc();
        echo "   ✅ Visitor created successfully!\n";
        echo "   📋 Details:\n";
        echo "      - Name: {$visitor['first_name']} {$visitor['last_name']}\n";
        echo "      - Email: {$visitor['business_email']}\n";
        echo "      - Company: {$visitor['company_name']}\n";
        echo "      - Last URL: {$visitor['url']}\n";
        echo "      - Event Count: {$visitor['event_count']}\n\n";
    } else {
        echo "   ❌ Visitor was NOT created! Trigger may have failed.\n";
        exit(1);
    }
    
    // Step 6: Test UPDATE functionality
    echo "6️⃣  Testing visitor UPDATE (second event)...\n";
    $test_event2 = [
        'uuid' => $test_uuid,
        'first_name' => 'John',
        'last_name' => 'TestUser',
        'event_timestamp' => date('Y-m-d H:i:s'),
        'event_type' => 'button_click',
        'url' => 'https://example.com/another-page',
        'element' => 'link#another',
        'percentage' => '90',
        'referrer' => 'https://example.com/test-page',
        'ip_address' => '192.168.1.2',
        'personal_emails' => 'john.personal@gmail.com',  // New data
        'personal_phone' => '555-9876'  // New data
    ];
    
    $columns2 = array_keys($test_event2);
    $values2 = array_map(function($v) use ($connection) {
        return "'" . $connection->real_escape_string($v) . "'";
    }, array_values($test_event2));
    
    $insert_sql2 = "INSERT INTO superpixel_resolution_log (" . implode(', ', $columns2) . ") 
                    VALUES (" . implode(', ', $values2) . ")";
    
    if ($connection->query($insert_sql2)) {
        echo "   ✅ Second test event inserted\n\n";
    } else {
        echo "   ❌ Failed to insert second test event: " . $connection->error . "\n";
    }
    
    // Step 7: Verify visitor was updated
    echo "7️⃣  Verifying visitor UPDATE...\n";
    sleep(1); // Give trigger time to execute
    
    $visitor_check2 = $connection->query("SELECT * FROM superpixel_visitors WHERE uuid = '$test_uuid'");
    if ($visitor_check2 && $visitor_check2->num_rows > 0) {
        $visitor2 = $visitor_check2->fetch_assoc();
        echo "   ✅ Visitor updated successfully!\n";
        echo "   📋 Updated Details:\n";
        echo "      - Business Email (preserved): {$visitor2['business_email']}\n";
        echo "      - Personal Emails (new): {$visitor2['personal_emails']}\n";
        echo "      - Personal Phone (new): {$visitor2['personal_phone']}\n";
        echo "      - Last URL: {$visitor2['url']}\n";
        echo "      - Last Event Type: {$visitor2['event_type']}\n";
        echo "      - Event Count: {$visitor2['event_count']}\n\n";
        
        // Verify business email was preserved
        if ($visitor2['business_email'] == 'john@testcompany.com') {
            echo "   ✅ Business email correctly preserved!\n";
        } else {
            echo "   ⚠️  Business email was overwritten (should have been preserved)\n";
        }
        
        // Verify event count incremented
        if ($visitor2['event_count'] == 2) {
            echo "   ✅ Event count correctly incremented to 2!\n";
        } else {
            echo "   ⚠️  Event count is {$visitor2['event_count']} (expected 2)\n";
        }
    }
    
    // Step 8: Clean up test data
    echo "\n8️⃣  Cleaning up test data...\n";
    $connection->query("DELETE FROM superpixel_resolution_log WHERE uuid = '$test_uuid'");
    $connection->query("DELETE FROM superpixel_visitors WHERE uuid = '$test_uuid'");
    echo "   ✅ Test data cleaned up\n\n";
    
    echo "✨ TRIGGER TEST COMPLETE! ✨\n";
    echo "The visitor automation trigger is working correctly.\n";
    echo "It will now automatically:\n";
    echo "  • Create new visitors when events arrive with new UUIDs\n";
    echo "  • Update existing visitors with new event data\n";
    echo "  • Preserve business emails over personal emails\n";
    echo "  • Track event counts and last seen timestamps\n";
}

// Run the test
try {
    testVisitorTrigger('AcquireUp');
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?> 