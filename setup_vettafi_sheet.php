<?php
// VettaFi Google Sheet Setup - One-time script
require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Drive;

// Configuration
$dbHost = '34.26.61.148';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$credentialsPath = '/opt/auto-pixel/credentials.json';

function setupVettaFiSheet() {
    global $credentialsPath, $dbHost, $dbUser, $dbPass;
    
    $clientName = 'VettaFi';
    
    try {
        echo "🚀 Starting VettaFi Google Sheet setup...\n";
        
        // Initialize Google clients
        $client = new Client();
        $client->setAuthConfig($credentialsPath);
        $client->setScopes([
            'https://www.googleapis.com/auth/spreadsheets',
            'https://www.googleapis.com/auth/drive'
        ]);
        
        // Enable OAuth delegation
        // $client->setSubject('scole@thynkdata.com');
        
        $sheets = new Sheets($client);
        $drive = new Drive($client);
        
        echo "✅ Google API clients initialized\n";
        
        // Create spreadsheet with proper title
        $spreadsheet = new Google\Service\Sheets\Spreadsheet([
            'properties' => [
                'title' => $clientName . '_Site_Visitors',
            ],
            'sheets' => [
                [
                    'properties' => [
                        'title' => 'Visitors',
                        'sheetId' => 0,
                        'gridProperties' => [
                            'rowCount' => 10010,  // 10,000 visitors + header + buffer
                            'columnCount' => 22,  // Updated for all columns
                            'frozenRowCount' => 1,
                        ],
                    ],
                ],
                [
                    'properties' => [
                        'title' => 'Events',
                        'sheetId' => 1,
                        'gridProperties' => [
                            'rowCount' => 100010,  // 100,000 events + header + buffer
                            'columnCount' => 18,   // Updated for all columns
                            'frozenRowCount' => 1,
                        ],
                    ],
                ],
            ],
        ]);
        
        $response = $sheets->spreadsheets->create($spreadsheet);
        $spreadsheetId = $response->spreadsheetId;
        $spreadsheetUrl = $response->spreadsheetUrl;
        
        echo "✅ Google Sheet created: {$spreadsheetUrl}\n";
        
        // Set up headers for Visitors sheet with all columns
        $visitorsHeaders = [[
            'UUID', 'First Name', 'Last Name', 'Company', 'Job Title', 'Emails', 'Phone',
            'Personal Address', 'City', 'State', 'Zip', 'First Seen', 'Last Seen', 'Event Count',
            'Last Visited URL', 'Last Element', 'Last Percentage', 'Last Referrer',
            'Last Timestamp', 'Last Event', 'NPN', 'CRD'
        ]];
        
        $sheets->spreadsheets_values->update(
            $spreadsheetId,
            'Visitors!A1:V1',
            new Google\Service\Sheets\ValueRange([
                'values' => $visitorsHeaders
            ]),
            ['valueInputOption' => 'RAW']
        );
        
        echo "✅ Visitors sheet headers set\n";
        
        // Set up headers for Events sheet with all columns
        $eventsHeaders = [[
            'Timestamp', 'Event Type', 'URL', 'Element', 'Referrer', 'IP Address', 
            'UUID', 'First Name', 'Last Name', 'Company', 'Job Title', 'Emails', 
            'Phone', 'City', 'State', 'HemSha256', 'NPN', 'CRD'
        ]];
        
        $sheets->spreadsheets_values->update(
            $spreadsheetId,
            'Events!A1:R1',
            new Google\Service\Sheets\ValueRange([
                'values' => $eventsHeaders
            ]),
            ['valueInputOption' => 'RAW']
        );
        
        echo "✅ Events sheet headers set\n";
        
        // Format headers (bold, background color)
        $requests = [
            [
                'repeatCell' => [
                    'range' => [
                        'sheetId' => 0, // Visitors sheet
                        'startRowIndex' => 0,
                        'endRowIndex' => 1,
                    ],
                    'cell' => [
                        'userEnteredFormat' => [
                            'backgroundColor' => [
                                'red' => 0.9,
                                'green' => 0.9,
                                'blue' => 0.9,
                            ],
                            'textFormat' => [
                                'bold' => true,
                            ],
                        ],
                    ],
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
                ],
            ],
            [
                'repeatCell' => [
                    'range' => [
                        'sheetId' => 1, // Events sheet
                        'startRowIndex' => 0,
                        'endRowIndex' => 1,
                    ],
                    'cell' => [
                        'userEnteredFormat' => [
                            'backgroundColor' => [
                                'red' => 0.9,
                                'green' => 0.9,
                                'blue' => 0.9,
                            ],
                            'textFormat' => [
                                'bold' => true,
                            ],
                        ],
                    ],
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
                ],
            ],
        ];
        
        $batchUpdateRequest = new Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
            'requests' => $requests
        ]);
        
        $sheets->spreadsheets->batchUpdate($spreadsheetId, $batchUpdateRequest);
        
        echo "✅ Headers formatted\n";
        
        // Make spreadsheet publicly readable
        $permission = new Google\Service\Drive\Permission([
            'type' => 'anyone',
            'role' => 'reader'
        ]);
        
        $drive->permissions->create($spreadsheetId, $permission);
        
        echo "✅ Sheet permissions set to public read\n";
        
        // Update pixel_sheets table with new sheet information
        $connection = new mysqli($dbHost, $dbUser, $dbPass, 'pixel');
        
        if ($connection->connect_error) {
            throw new Exception("Database connection failed: " . $connection->connect_error);
        }
        
        $stmt = $connection->prepare("UPDATE pixel_sheets SET sheet_id = ?, sheet_url = ? WHERE client_name = 'VettaFi'");
        $stmt->bind_param("ss", $spreadsheetId, $spreadsheetUrl);
        
        if ($stmt->execute()) {
            echo "✅ Database updated with sheet information\n";
        } else {
            throw new Exception("Failed to update database: " . $stmt->error);
        }
        
        $stmt->close();
        $connection->close();
        
        echo "\n🎉 VettaFi Google Sheet setup complete!\n";
        echo "📊 Sheet URL: {$spreadsheetUrl}\n";
        echo "🆔 Sheet ID: {$spreadsheetId}\n";
        echo "\n💡 Next steps:\n";
        echo "   1. The sheet is ready for data syncing\n";
        echo "   2. Run sync scripts to populate with existing VettaFi data\n";
        echo "   3. Test the pixel tracking integration\n";
        
        return [
            'success' => true,
            'sheetId' => $spreadsheetId,
            'sheetUrl' => $spreadsheetUrl
        ];
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Run the setup
echo "=== VettaFi Google Sheet Setup ===\n\n";
$result = setupVettaFiSheet();

if (!$result['success']) {
    exit(1);
}
?> 