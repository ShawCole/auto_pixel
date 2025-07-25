#!/bin/bash

# Development Testing Script for Google Sheets Integration
# This script sets up a local testing environment

echo "🧪 Setting up Google Sheets testing environment..."

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if we're in the right directory
if [ ! -f "package.json" ]; then
    print_error "Please run this script from the Auto_Pixel root directory"
    exit 1
fi

print_status "Creating development database table..."

# Create the pixel_sheets table in the pixel database
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' pixel << 'EOF'
CREATE TABLE IF NOT EXISTS `pixel_sheets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_name` varchar(100) NOT NULL,
  `pixel_id` varchar(100) NOT NULL,
  `sheet_id` varchar(100) NOT NULL,
  `sheet_url` text,
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_client` (`client_name`),
  KEY `idx_pixel` (`pixel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
EOF

if [ $? -eq 0 ]; then
    print_success "Database table created successfully"
else
    print_error "Failed to create database table"
    exit 1
fi

print_status "Setting up PHP dependencies..."

# Check if composer is installed
if ! command -v composer &> /dev/null; then
    print_warning "Composer not found. Installing..."
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
fi

# Install Google API dependencies
if [ ! -d "vendor" ]; then
    print_status "Installing Google API dependencies..."
    composer require google/apiclient
else
    print_status "Dependencies already installed"
fi

print_status "Setting up test scripts..."

# Create a test version of the sheet creation script
cat > test_create_sheet.php << 'EOF'
<?php
// Test version of Google Sheets Creation Script
require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Drive;

// Configuration
$dbHost = '34.31.66.104';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$credentialsPath = '/etc/auto-pixel/thynk-intent-dev-463522-046f81c95700.json';

// Test parameters
$clientName = 'TEST_CLIENT_' . date('Ymd_His');
$pixelId = 'test-pixel-' . time();

function createTestGoogleSheet($clientName, $pixelId) {
    global $credentialsPath, $dbHost, $dbUser, $dbPass;
    
    try {
        // Initialize Google clients
        $client = new Client();
        $client->setAuthConfig($credentialsPath);
        $client->setScopes([
            'https://www.googleapis.com/auth/spreadsheets',
            'https://www.googleapis.com/auth/drive'
        ]);
        
        $sheets = new Sheets($client);
        $drive = new Drive($client);
        
        // Create spreadsheet with TEST prefix
        $spreadsheet = new Google\Service\Sheets\Spreadsheet([
            'properties' => [
                'title' => 'TEST_' . $clientName . '_Site_Visitors',
            ],
            'sheets' => [
                [
                    'properties' => [
                        'title' => 'Visitors',
                        'sheetId' => 0,
                        'gridProperties' => [
                            'rowCount' => 10010,
                            'columnCount' => 12,
                            'frozenRowCount' => 1,
                        ],
                    ],
                ],
                [
                    'properties' => [
                        'title' => 'Events',
                        'sheetId' => 1,
                        'gridProperties' => [
                            'rowCount' => 100010,
                            'columnCount' => 8,
                            'frozenRowCount' => 1,
                        ],
                    ],
                ],
            ],
        ]);
        
        $response = $sheets->spreadsheets->create($spreadsheet);
        $spreadsheetId = $response->spreadsheetId;
        $spreadsheetUrl = $response->spreadsheetUrl;
        
        // Set up headers
        $visitorsHeaders = [['UUID', 'First Name', 'Last Name', 'Company', 'Job Title', 'Email', 'Phone', 'City', 'State', 'First Seen', 'Last Seen', 'Event Count']];
        $eventsHeaders = [['Event Time', 'Event Type', 'UUID', 'Name', 'Company', 'Page URL', 'Referrer', 'IP Address']];
        
        $sheets->spreadsheets_values->update($spreadsheetId, 'Visitors!A1:L1', new Google\Service\Sheets\ValueRange(['values' => $visitorsHeaders]), ['valueInputOption' => 'RAW']);
        $sheets->spreadsheets_values->update($spreadsheetId, 'Events!A1:H1', new Google\Service\Sheets\ValueRange(['values' => $eventsHeaders]), ['valueInputOption' => 'RAW']);
        
        // Make public
        $permission = new Google\Service\Drive\Permission(['type' => 'anyone', 'role' => 'reader']);
        $drive->permissions->create($spreadsheetId, $permission);
        
        // Store in database
        $mysqli = new mysqli($dbHost, $dbUser, $dbPass, 'pixel');
        if ($mysqli->connect_error) {
            throw new Exception("Database connection failed: " . $mysqli->connect_error);
        }
        
        $stmt = $mysqli->prepare("INSERT INTO pixel_sheets (client_name, pixel_id, sheet_id, sheet_url) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $clientName, $pixelId, $spreadsheetId, $spreadsheetUrl);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to save sheet info: " . $stmt->error);
        }
        
        $stmt->close();
        $mysqli->close();
        
        return ['success' => true, 'sheetId' => $spreadsheetId, 'sheetUrl' => $spreadsheetUrl];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Execute test
$result = createTestGoogleSheet($clientName, $pixelId);
echo json_encode($result, JSON_PRETTY_PRINT);
?>
EOF

print_success "Test script created: test_create_sheet.php"

print_status "Creating test sync script..."

# Create a test version of the sync script
cat > test_sync.php << 'EOF'
<?php
// Test version of Google Sheets Sync Script
require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;

$dbHost = '34.31.66.104';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$credentialsPath = '/etc/auto-pixel/thynk-intent-dev-463522-046f81c95700.json';

// Test configuration
$TEST_CLIENT = 'TEST_CLIENT_' . date('Ymd'); // Today's test client

function getGoogleClient() {
    global $credentialsPath;
    $client = new Client();
    $client->setAuthConfig($credentialsPath);
    $client->setScopes(['https://www.googleapis.com/auth/spreadsheets']);
    return $client;
}

function testSync() {
    global $dbHost, $dbUser, $dbPass, $TEST_CLIENT;
    
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass);
    if ($mysqli->connect_error) {
        return ['success' => false, 'error' => "MySQL connection failed: " . $mysqli->connect_error];
    }
    
    // Get test sheet
    $sql = "SELECT * FROM pixel.pixel_sheets WHERE client_name LIKE 'TEST_CLIENT_%' ORDER BY created_at DESC LIMIT 1";
    $result = $mysqli->query($sql);
    
    if (!$result || $result->num_rows === 0) {
        return ['success' => false, 'error' => 'No test sheets found. Run test_create_sheet.php first.'];
    }
    
    $sheet = $result->fetch_assoc();
    
    // Select client database
    $clientDb = $sheet['client_name'];
    if (!$mysqli->select_db($clientDb)) {
        return ['success' => false, 'error' => "Could not select database $clientDb"];
    }
    
    // Check if tables exist
    $tables = $mysqli->query("SHOW TABLES LIKE 'superpixel_visitors'");
    if ($tables->num_rows === 0) {
        return ['success' => false, 'error' => "Table superpixel_visitors does not exist in $clientDb"];
    }
    
    $client = getGoogleClient();
    $service = new Sheets($client);
    
    // Add some test data
    $testData = [
        ['2025-01-27 10:00:00', 'page_view', 'test-uuid-1', 'John Doe', 'Test Company', 'https://example.com', 'https://google.com', '192.168.1.1'],
        ['2025-01-27 10:01:00', 'click', 'test-uuid-1', 'John Doe', 'Test Company', 'https://example.com/page2', 'https://example.com', '192.168.1.1']
    ];
    
    try {
        $range = 'Events!A2';
        $body = new ValueRange(['values' => $testData]);
        $params = ['valueInputOption' => 'RAW'];
        $service->spreadsheets_values->update($sheet['sheet_id'], $range, $body, $params);
        
        return ['success' => true, 'message' => 'Test data synced successfully', 'sheetUrl' => $sheet['sheet_url']];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Sync failed: ' . $e->getMessage()];
    }
}

$result = testSync();
echo json_encode($result, JSON_PRETTY_PRINT);
?>
EOF

print_success "Test sync script created: test_sync.php"

print_status "Setting up Node.js test environment..."

# Create a test version of the server
cat > test_server.js << 'EOF'
const express = require('express');
const { exec } = require('child_process');
const { promisify } = require('util');

const execAsync = promisify(exec);
const app = express();
const PORT = 4001; // Different port for testing

app.use(express.json());

// Test endpoint that simulates the full flow
app.post('/test-generate', async (req, res) => {
    try {
        const { client, website } = req.body;
        
        if (!client || !website) {
            return res.status(400).json({ error: 'Client name and website URL are required' });
        }
        
        console.log(`🧪 Testing pixel generation for: ${client}`);
        
        // Simulate pixel creation (just return a fake pixel code)
        const pixelCode = `<script>console.log('Test pixel for ${client}');</script>`;
        const pixelId = `${client.toLowerCase()}-test-${Date.now()}`;
        
        // Create Google Sheet
        console.log('📊 Creating test Google Sheet...');
        const { stdout, stderr } = await execAsync(`php test_create_sheet.php`);
        
        if (stderr) {
            console.log('⚠️ PHP stderr:', stderr);
        }
        
        const result = JSON.parse(stdout);
        
        if (result.success) {
            console.log('✅ Test sheet created:', result.sheetUrl);
            res.json({
                pixelSnippet: pixelCode,
                sheetUrl: result.sheetUrl,
                message: `Test pixel generated successfully for ${client}`,
                databaseSetup: true
            });
        } else {
            console.log('❌ Test sheet creation failed:', result.error);
            res.json({
                pixelSnippet: pixelCode,
                error: result.error,
                message: `Test pixel generated but sheet creation failed for ${client}`,
                databaseSetup: true
            });
        }
        
    } catch (error) {
        console.error('💥 Test error:', error);
        res.status(500).json({ error: error.message });
    }
});

// Test sync endpoint
app.post('/test-sync', async (req, res) => {
    try {
        console.log('🔄 Testing data sync...');
        const { stdout, stderr } = await execAsync(`php test_sync.php`);
        
        if (stderr) {
            console.log('⚠️ PHP stderr:', stderr);
        }
        
        const result = JSON.parse(stdout);
        res.json(result);
        
    } catch (error) {
        console.error('💥 Sync test error:', error);
        res.status(500).json({ error: error.message });
    }
});

app.listen(PORT, () => {
    console.log(`🧪 Test server running on http://localhost:${PORT}`);
    console.log('📋 Test endpoints:');
    console.log('   POST /test-generate - Test pixel generation with sheet creation');
    console.log('   POST /test-sync - Test data sync to sheets');
});
EOF

print_success "Test server created: test_server.js"

print_status "Creating test frontend..."

# Create a simple test HTML file
cat > test_frontend.html << 'EOF'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google Sheets Test</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-2xl mx-auto bg-white rounded-lg shadow p-6">
        <h1 class="text-2xl font-bold mb-6">Google Sheets Integration Test</h1>
        
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-2">Client Name</label>
                <input type="text" id="clientName" class="w-full p-2 border rounded" placeholder="test_client">
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-2">Website URL</label>
                <input type="text" id="websiteUrl" class="w-full p-2 border rounded" placeholder="https://example.com">
            </div>
            
            <button onclick="testGenerate()" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                Test Generate
            </button>
            
            <button onclick="testSync()" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600 ml-2">
                Test Sync
            </button>
        </div>
        
        <div id="result" class="mt-6 p-4 bg-gray-50 rounded hidden">
            <h3 class="font-bold mb-2">Result:</h3>
            <pre id="resultText" class="text-sm"></pre>
        </div>
    </div>

    <script>
        async function testGenerate() {
            const client = document.getElementById('clientName').value;
            const website = document.getElementById('websiteUrl').value;
            
            if (!client || !website) {
                alert('Please fill in both fields');
                return;
            }
            
            try {
                const response = await fetch('http://localhost:4001/test-generate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ client, website })
                });
                
                const result = await response.json();
                showResult(result);
            } catch (error) {
                showResult({ error: error.message });
            }
        }
        
        async function testSync() {
            try {
                const response = await fetch('http://localhost:4001/test-sync', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });
                
                const result = await response.json();
                showResult(result);
            } catch (error) {
                showResult({ error: error.message });
            }
        }
        
        function showResult(data) {
            document.getElementById('result').classList.remove('hidden');
            document.getElementById('resultText').textContent = JSON.stringify(data, null, 2);
        }
    </script>
</body>
</html>
EOF

print_success "Test frontend created: test_frontend.html"

echo ""
print_success "🎉 Development testing environment setup complete!"
echo ""
echo "📋 Next steps:"
echo "1. Start test server: node test_server.js"
echo "2. Open test_frontend.html in browser"
echo "3. Test the full flow without affecting production"
echo ""
echo "🔧 Test commands:"
echo "   php test_create_sheet.php          # Test sheet creation"
echo "   php test_sync.php                  # Test data sync"
echo "   node test_server.js                # Start test server"
echo ""
print_warning "Remember: Test sheets will have 'TEST_' prefix to avoid confusion" 