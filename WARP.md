# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Repository Overview

This is an automated pixel tracking system that generates unique pixels for clients, creates dedicated databases, Google Sheets, and synchronizes data in real-time. The system consists of a Node.js/TypeScript API server, PHP webhook processors, and various sync/monitoring scripts.

## Common Commands

### Development

```bash
# Install dependencies (all workspaces)
npm run install:all

# Run development servers
npm run dev  # Runs both server and web concurrently

# Server only (from server/ directory)
npm run dev         # Development with hot reload
npm run build       # Build TypeScript to JavaScript
npm run start       # Production start
```

### Testing

```bash
# Test webhook endpoint (replace CLIENT_NAME with actual client)
curl -X POST "https://hook.thynkdata.com/pixel_import.php?client=CLIENT_NAME" \
-H "Content-Type: application/json" \
-d '{"resolution":[{"UUID":"test","FIRST_NAME":"John","LAST_NAME":"Doe","visited_url":"https://example.com","element":"Button","percentage":85,"referrer":"https://google.com","timestamp":"'$(date +%s)'","event_type":"click","ip_address":"192.168.1.1"}]}'

# Test API endpoints
curl -X POST http://localhost:4000/generate \
-H "Content-Type: application/json" \
-d '{"client":"TEST_CLIENT","website":"https://example.com"}'

curl http://localhost:4000/health
```

### Database Operations

```bash
# Check client database stats
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' CLIENT_NAME -e "SELECT COUNT(*) as events FROM superpixel_resolution_log; SELECT COUNT(*) as visitors FROM superpixel_visitors;"

# Check specific visitor data
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' CLIENT_NAME -e "SELECT uuid, first_name, last_name, url, element FROM superpixel_visitors LIMIT 5;"

# Check recent events
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' CLIENT_NAME -e "SELECT uuid, event_type, url, timestamp FROM superpixel_resolution_log ORDER BY id DESC LIMIT 5;"
```

### Sync Operations

```bash
# Force immediate sync of all Google Sheets
php force_sync.php

# Run smart sync manually
php smart_sync.php

# Monitor new sheets detection
nohup php monitor_new_sheets.php > /dev/null 2>&1 &

# Check for problematic database schemas
php check_problematic_sheets.php

# Delete a client completely
php delete_client.php 'CLIENT_NAME'
```

### Production Monitoring

```bash
# Monitor all critical logs (run in separate terminals)
tail -f /opt/auto-pixel/sync.log                                    # Smart sync system logs
tail -f /opt/auto-pixel/monitor.log                                 # New sheet detection logs  
tail -f /var/www/hook.thynkdata.com/pixel_import_debug.log         # Webhook processing logs
pm2 logs auto-pixel-api --lines 50                                 # Node.js API server logs

# Optional: focus on key webhook lines in real time
# (AcquireUp | lifecycle markers | success/failure | error glyphs)
tail -f /var/www/hook.thynkdata.com/pixel_import_debug.log \
  | grep --line-buffered -E 'AcquireUp|New webhook|Raw input|Processing|Successfully|Failed|error|❌|✅'

# Check process status
pm2 status                    # Node.js API server status
ps aux | grep smart_sync      # Smart sync process
ps aux | grep monitor_new     # Monitor process
crontab -l                    # Scheduled tasks
```

### Deployment

```bash
# Traditional deployment
chmod +x deploy.sh && ./deploy.sh

# Docker deployment  
chmod +x docker-deploy.sh && ./docker-deploy.sh

# Quick deploy script
./quick-deploy.sh
```

### Operations (pixel-php VM)

Sourced from SOP v1.1.0 (see SOP_Updated.md) — key highlights only.

- GCP access: Console → Compute Engine → VM instances → SSH into pixel-php
  - CLI alternative: gcloud compute ssh pixel-php --project=thynk-intent-dev --zone=us-central1-a
- MySQL access tip: whitelist your IP (Cloud SQL → intent-dev → Connections → Networking → Add Network)
- DB connectivity smoke test:
  ```bash
  mysql -h 34.31.66.104 -u root -p'AccuPoint01!' -e "SELECT 'DB Connected' AS status;"
  ```
- Daily ops checklist:
  1) Open four terminals (sync, monitor, webhook, API logs)
  2) Confirm no errors in sync.log; verify client DB counts vs. sheet rows
  3) Run php force_sync.php if data is stale
  4) Run php check_problematic_sheets.php and confirm no issues
  5) pm2 restart all if API or sync stalls
  6) Truncate large logs (>100 MB)

## Architecture Overview

### Core Components

**Node.js/TypeScript API Server** (`server/src/`)
- Handles pixel generation requests via `/generate` endpoint
- Manages database schema creation for new clients
- Integrates with AudienceLab automation for pixel creation
- Triggers Google Sheets creation and sync processes
- Uses Express.js with CORS configured for multiple frontend domains

**PHP Webhook System** (`pixel_import.php`)
- Ingests real-time event data from generated pixels
- Processes SimpleAudience data format (UPPERCASE fields → lowercase database columns)
- Handles visitor deduplication and email normalization
- Sends notification emails for new data

**Smart Sync System** (`smart_sync.php`)
- Orchestrates data synchronization between MySQL and Google Sheets
- Runs every 5 minutes via cron with staggered processing
- Handles both visitor data and event logs
- Includes visitor consistency checks and backfill capabilities

**Monitor System** (`monitor_new_sheets.php`)
- Continuous monitoring for new Google Sheets
- Triggers immediate syncs for newly created sheets
- Runs as background process

### Data Flow

1. **Pixel Generation**: API receives `/generate` request → Creates database schema → Calls AudienceLab automation → Returns pixel code
2. **Data Ingestion**: Pixel fires → Webhook receives data → Saves to client database → Processes visitor records
3. **Synchronization**: Smart sync detects new data → Updates Google Sheets → Maintains data consistency

### Database Structure

**Template Database** (`pixel`)
- Contains schema template for new client databases
- Houses `pixel_sheets` metadata table tracking all client sheets

**Client Databases** (per client)
- `superpixel_visitors` - Deduplicated visitor records with contact info
- `superpixel_resolution_log` - Event logs with interaction details

### Key Architectural Patterns

**Field Mapping**: SimpleAudience uses UPPERCASE field names (`UUID`, `FIRST_NAME`) which are mapped to lowercase database columns (`uuid`, `first_name`) in the webhook processor.

**Visitor Deduplication**: Uses UUID-based deduplication with special handling for non-dedupe test personas.

**Staggered Processing**: Smart sync processes maximum 4 sheets per run with 15-second delays to stay within 5-minute cron window.

**Error Recovery**: Includes diagnostic scripts (`check_problematic_sheets.php`) to identify and fix schema issues.

## Important File Locations

### Configuration Files
- `config.php` - Database connection configuration
- `server/.env` - Node.js environment variables
- `docker-compose.yml` - Container orchestration
- `ecosystem.config.js` - PM2 process management

### Core Scripts
- `server/src/index.ts` - Main API server
- `pixel_import.php` - Webhook data processor
- `smart_sync.php` - Main sync orchestrator
- `force_sync.php` - Manual sync trigger
- `monitor_new_sheets.php` - New sheet detection

### Utility Scripts
- `check_problematic_sheets.php` - Schema diagnostics
- `delete_client.php` - Client cleanup
- `create_client_sheet.php` - Google Sheets creation
- `visitor_upsert_functions.php` - Standardized visitor operations

### Library Files
- `server/src/lib/db.ts` - Database connection and schema management
- `server/src/lib/audienceLab.ts` - AudienceLab automation integration
- `server/src/lib/googleSheets.ts` - Google Sheets API integration

## Development Notes

### Environment Variables Required

**Node.js Server**:
- `DB_HOST`, `DB_USER`, `DB_PASS` - Database connection
- `AUDLAB_USERNAME`, `AUDLAB_PASSWORD` - AudienceLab credentials
- `NODE_ENV` - Environment (development/production)

**PHP Scripts**:
- Database credentials are hardcoded in `config.php`
- Google Sheets service account key at `/etc/auto-pixel/thynk-intent-dev-463522-046f81c95700.json`

### Testing Strategy

Always use URL parameters for webhook testing (`?client=CLIENT_NAME`) as database payload method doesn't work reliably.

For database operations, ensure the client database exists before testing webhook endpoints.

### Common Issues

**"Unknown column" errors**: Indicates missing schema columns that need to be added via ALTER TABLE statements.

**"Unknown database" errors**: Orphaned entries in `pixel_sheets` table that need cleanup via `delete_client.php`.

**Sync failures**: Usually schema-related, use `check_problematic_sheets.php` to diagnose.

### Security Considerations

Database credentials are currently hardcoded in PHP files. Google Sheets uses service account delegation to `scole@thynkdata.com`. Webhook includes basic input validation and SQL injection prevention.
