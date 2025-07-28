// import { google } from 'googleapis';
// import { GoogleAuth } from 'google-auth-library';
// import { sheets_v4 } from 'googleapis';
// import { query } from './db';

// const SCOPES = ['https://www.googleapis.com/auth/spreadsheets', 'https://www.googleapis.com/auth/drive'];

// export async function createClientSheet(clientName: string, pixelId: string): Promise<{ sheetId: string; sheetUrl: string }> {
//     console.log(`Creating Google Sheet for client: ${clientName}`);

//     // Initialize auth
//     const auth = new GoogleAuth({
//         keyFile: '/etc/auto-pixel/google-credentials.json',
//         scopes: SCOPES,
//     });

//     const sheets = google.sheets({ version: 'v4', auth });
//     const drive = google.drive({ version: 'v3', auth });

//     try {
//         // Create spreadsheet
//         const response = await sheets.spreadsheets.create({
//             requestBody: {
//                 properties: {
//                     title: `${clientName} - Pixel Tracking Data`,
//                 },
//                 sheets: [
//                     {
//                         properties: {
//                             title: 'Visitors',
//                             gridProperties: {
//                                 frozenRowCount: 1,
//                             },
//                         },
//                     },
//                     {
//                         properties: {
//                             title: 'Events Log',
//                             gridProperties: {
//                                 frozenRowCount: 1,
//                             },
//                         },
//                     },
//                 ],
//             },
//         });

//         const spreadsheetId = response.data.spreadsheetId!;
//         const spreadsheetUrl = response.data.spreadsheetUrl!;

//         // Set up headers for Visitors sheet
//         const visitorsHeaders = [
//             ['UUID', 'First Name', 'Last Name', 'Company', 'Job Title', 'Email', 'Phone',
//                 'City', 'State', 'First Seen', 'Last Seen', 'Event Count']
//         ];

//         await sheets.spreadsheets.values.update({
//             spreadsheetId,
//             range: 'Visitors!A1:L1',
//             valueInputOption: 'RAW',
//             requestBody: {
//                 values: visitorsHeaders,
//             },
//         });

//         // Set up headers for Events Log sheet
//         const eventsHeaders = [
//             ['Event Time', 'Event Type', 'UUID', 'Name', 'Company', 'Page URL', 'Referrer', 'IP Address']
//         ];

//         await sheets.spreadsheets.values.update({
//             spreadsheetId,
//             range: 'Events Log!A1:H1',
//             valueInputOption: 'RAW',
//             requestBody: {
//                 values: eventsHeaders,
//             },
//         });

//         // Format headers (bold, freeze)
//         const requests = [
//             {
//                 repeatCell: {
//                     range: {
//                         sheetId: 0, // Visitors sheet
//                         startRowIndex: 0,
//                         endRowIndex: 1,
//                     },
//                     cell: {
//                         userEnteredFormat: {
//                             textFormat: {
//                                 bold: true,
//                             },
//                             backgroundColor: {
//                                 red: 0.9,
//                                 green: 0.9,
//                                 blue: 0.9,
//                             },
//                         },
//                     },
//                     fields: 'userEnteredFormat(textFormat,backgroundColor)',
//                 },
//             },
//             {
//                 repeatCell: {
//                 range: {
//                     sheetId: 1, // Events Log sheet
//                     startRowIndex: 0,
//                     endRowIndex: 1,
//                 },
//                 cell: {
//                     userEnteredFormat: {
//                         textFormat: {
//                             bold: true,
//                         },
//                         backgroundColor: {
//                             red: 0.9,
//                             green: 0.9,
//                             blue: 0.9,
//                         },
//                     },
//                 },
//                 fields: 'userEnteredFormat(textFormat,backgroundColor)',
//             },
//         },
//     ];

//     await sheets.spreadsheets.batchUpdate({
//         spreadsheetId,
//         requestBody: {
//             requests,
//         },
//     });

//     // Get service account email
//     const authClient = await auth.getClient();
//     const serviceAccountEmail = (authClient as any).email;

//     // Share with service account (already has access as creator)
//     // Optionally share with specific users here

//     // Store in database
//     await query(
//         `INSERT INTO pixel_sheets (client_name, pixel_id, sheet_id, sheet_url) 
//    VALUES (?, ?, ?, ?)`,
//         [clientName, pixelId, spreadsheetId, spreadsheetUrl]
//     );

//     console.log(`Sheet created successfully: ${spreadsheetUrl}`);

//     return {
//         sheetId: spreadsheetId,
//         sheetUrl: spreadsheetUrl,
//     };
// } catch (error) {
//     console.error('Error creating Google Sheet:', error);
//     throw error;
// }
// } 