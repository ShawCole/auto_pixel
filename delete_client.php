<?php
/**
 * Delete Client Script
 * Removes a client from pixel_sheets and cleans up their database tables
 */

$dbHost = '34.31.66.104';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';

function deleteClientFromDatabase($clientName) {
    global $dbHost, $dbUser, $dbPass;
    
    $connection = new mysqli($dbHost, $dbUser, $dbPass);
    if ($connection->connect_error) {
        echo "❌ Connection failed: " . $connection->connect_error . "\n";
        return false;
    }
    
    try {
        echo "🗄️  Deleting client data from database: $clientName\n";
        
        // Delete from pixel_sheets table
        echo "1️⃣  Removing from pixel_sheets table...\n";
        $stmt = $connection->prepare('DELETE FROM pixel.pixel_sheets WHERE client_name = ?');
        $stmt->bind_param('s', $clientName);
        
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            if ($affected > 0) {
                echo "   ✅ Removed $affected entry from pixel_sheets\n";
            } else {
                echo "   ℹ️  No entries found in pixel_sheets for $clientName\n";
            }
        } else {
            echo "   ❌ Failed to remove from pixel_sheets: " . $stmt->error . "\n";
        }
        $stmt->close();
        
        // Check if client database exists
        echo "2️⃣  Checking if database '$clientName' exists...\n";
        $stmt = $connection->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->bind_param('s', $clientName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo "   ℹ️  Database '$clientName' does not exist - skipping table cleanup\n";
            $stmt->close();
            $connection->close();
            return true;
        }
        $stmt->close();
        
        // Database exists, proceed with table cleanup
        echo "   ✅ Database '$clientName' exists - proceeding with cleanup\n";
        
        // Delete from client's superpixel_visitors table
        echo "3️⃣  Clearing superpixel_visitors table...\n";
        $query = "DELETE FROM `$clientName`.superpixel_visitors";
        if ($connection->query($query)) {
            $affected = $connection->affected_rows;
            echo "   ✅ Deleted $affected records from superpixel_visitors\n";
        } else {
            echo "   ⚠️  Failed to clear superpixel_visitors: " . $connection->error . "\n";
        }
        
        // Delete from client's superpixel_resolution_log table
        echo "4️⃣  Clearing superpixel_resolution_log table...\n";
        $query = "DELETE FROM `$clientName`.superpixel_resolution_log";
        if ($connection->query($query)) {
            $affected = $connection->affected_rows;
            echo "   ✅ Deleted $affected records from superpixel_resolution_log\n";
        } else {
            echo "   ⚠️  Failed to clear superpixel_resolution_log: " . $connection->error . "\n";
        }
        
        echo "✅ Client '$clientName' successfully cleaned up from database\n";
        return true;
        
    } catch (Exception $e) {
        echo "❌ Error deleting client from database: " . $e->getMessage() . "\n";
        return false;
    } finally {
        $connection->close();
    }
}

// Get client name from command line argument
if ($argc < 2) {
    echo "❌ Usage: php delete_client.php <client_name>\n";
    echo "Example: php delete_client.php 'Active_Wealth_Management-Retirement_Results'\n";
    exit(1);
}

$clientName = $argv[1];

echo "🚀 Starting client deletion for: $clientName\n";
echo str_repeat("=", 60) . "\n";

$success = deleteClientFromDatabase($clientName);

echo str_repeat("=", 60) . "\n";
if ($success) {
    echo "🎉 Client deletion completed successfully!\n";
} else {
    echo "❌ Client deletion failed!\n";
    exit(1);
}
?> 