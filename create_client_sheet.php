<?php
// Google Sheets Creation Script - Called by Node.js server
require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Drive;

// Configuration
$dbHost = '34.31.66.104';
$dbUser = 'root';
$dbPass = 'AccuPoint01!';
$credentialsPath = '/etc/auto-pixel/thynk-intent-dev-463522-046f81c95700.json';

// Get client name and pixel ID from command line arguments
if ($argc < 3) {
    die(json_encode(['error' => 'Usage: php create_client_sheet.php CLIENT_NAME PIXEL_ID']));
}

$clientName = $argv[1];
$pixelId = $argv[2];

function createGoogleSheet($clientName, $pixelId) {
    global $credentialsPath, $dbHost, $dbUser, $dbPass;
    
    try {
        // Initialize Google clients
        $client = new Client();
        $client->setAuthConfig($credentialsPath);
        $client->setScopes([
            'https://www.googleapis.com/auth/spreadsheets',
            'https://www.googleapis.com/auth/drive'
        ]);
        
        // Enable OAuth delegation to create files in user's Drive
        $client->setSubject('scole@thynkdata.com');
        
        $sheets = new Sheets($client);
        $drive = new Drive($client);
        
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
                            'rowCount' => 100010,  // 100,000 events + header + buffer
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
        
        // Set up headers for Visitors sheet with all new columns
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
        
        // Set up headers for Events sheet with all new columns
        $eventsHeaders = [[
            'Timestamp', 'Event Type', 'URL', 'Elements', 'Referrer', 'IP Address', 
            'UUID', 'First Name', 'Last Name', 'Company', 'Job Title', 'Emails', 
            'Phone', 'City', 'State', 'HemSha256'
        ]];
        
        $sheets->spreadsheets_values->update(
            $spreadsheetId,
            'Events!A1:P1',
            new Google\Service\Sheets\ValueRange([
                'values' => $eventsHeaders
            ]),
            ['valueInputOption' => 'RAW']
        );
        
        // Format headers (bold, background color)
        $requests = [
            [
                'repeatCell' => [
                    'range' => [
                        'sheetId' => 0,
                        'startRowIndex' => 0,
                        'endRowIndex' => 1,
                    ],
                    'cell' => [
                        'userEnteredFormat' => [
                            'textFormat' => ['bold' => true],
                            'backgroundColor' => [
                                'red' => 0.85,
                                'green' => 0.85,
                                'blue' => 0.85,
                            ],
                        ],
                    ],
                    'fields' => 'userEnteredFormat(textFormat,backgroundColor)',
                ],
            ],
            [
                'repeatCell' => [
                    'range' => [
                        'sheetId' => 1,
                        'startRowIndex' => 0,
                        'endRowIndex' => 1,
                    ],
                    'cell' => [
                        'userEnteredFormat' => [
                            'textFormat' => ['bold' => true],
                            'backgroundColor' => [
                                'red' => 0.85,
                                'green' => 0.85,
                                'blue' => 0.85,
                            ],
                        ],
                    ],
                    'fields' => 'userEnteredFormat(textFormat,backgroundColor)',
                ],
            ],
        ];
        
        $sheets->spreadsheets->batchUpdate(
            $spreadsheetId,
            new Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                'requests' => $requests
            ])
        );
        
        // Make the sheet public (anyone with link can view)
        $permission = new Google\Service\Drive\Permission([
            'type' => 'anyone',
            'role' => 'reader',
        ]);
        
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
        
        return [
            'success' => true,
            'sheetId' => $spreadsheetId,
            'sheetUrl' => $spreadsheetUrl
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Execute and return JSON result
$result = createGoogleSheet($clientName, $pixelId);
echo json_encode($result);
?> 