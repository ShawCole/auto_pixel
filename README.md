# Auto Pixel Tracking System

A fully automated pixel tracking system that generates unique pixels for clients, creates dedicated databases, Google Sheets, and synchronizes data in real-time.

## 🏗️ **System Architecture**

### **Core Components**
- **Node.js API Server** (`server/src/index.ts`) - Handles pixel generation and triggers Google Sheet creation
- **PHP Webhook** (`pixel_import.php`) - Ingests real-time event data from pixels
- **Smart Sync System** - Automatically synchronizes database data to Google Sheets
- **Monitor System** - Detects new sheets and triggers immediate syncs

### **Database Structure**
- **Template Database** (`pixel`) - Contains schema for new client databases
- **Client Databases** - Individual databases for each client with `superpixel_visitors` and `superpixel_resolution_log` tables
- **Metadata Database** (`pixel`) - Tracks pixel sheets and sync status

## 📁 **Key Files & Scripts**

### **Core PHP Scripts**
- `smart_sync.php` - Main sync orchestrator (runs every 5 minutes via cron)
- `force_sync.php` - Immediate sync of all sheets (manual trigger)
- `monitor_new_sheets.php` - Continuous monitoring for new sheets
- `create_client_sheet.php` - Creates Google Sheets for new clients
- `pixel_import.php` - Webhook for ingesting pixel data

### **Configuration Files**
- `nginx.conf` - Web server configuration
- `docker-compose.yml` - Container orchestration
- `package.json` - Node.js dependencies
- `tsconfig.json` - TypeScript configuration

## 🔧 **Setup & Deployment**

### **Initial Setup**
```bash
# Clone repository
git clone <repository-url>
cd Auto_Pixel

# Install dependencies
npm install
cd server && npm install

# Configure environment
cp .env.example .env
# Edit .env with your settings

# Deploy
./deploy.sh
```

### **Service Management**
```bash
# Start all services
pm2 start ecosystem.config.js

# Monitor services
pm2 status
pm2 logs

# Restart services
pm2 restart all
```

## 📊 **Monitoring & Logs**

### **Critical Log Files**
- `/opt/auto-pixel/sync.log` - Smart sync system logs
- `/opt/auto-pixel/monitor.log` - New sheet detection logs
- `/var/www/hook.thynkdata.com/pixel_import_debug.log` - Webhook processing logs
- `pm2 logs auto-pixel-api` - Node.js API server logs

### **Real-time Monitoring**
```bash
# Monitor all logs simultaneously
tail -f /opt/auto-pixel/sync.log
tail -f /opt/auto-pixel/monitor.log  
tail -f /var/www/hook.thynkdata.com/pixel_import_debug.log
pm2 logs auto-pixel-api --lines 50
```

## 🚀 **Quick Commands**

### **Force Operations**
```bash
php force_sync.php              # Sync all sheets immediately
php smart_sync.php              # Run smart sync manually
php monitor_new_sheets.php      # Start monitor process
```

### **Database Checks**
```bash
# Check client data
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' CLIENT_NAME -e "SELECT COUNT(*) as events FROM superpixel_resolution_log; SELECT COUNT(*) as visitors FROM superpixel_visitors;"

# Check specific data
mysql -h 34.31.66.104 -u root -p'AccuPoint01!' CLIENT_NAME -e "SELECT uuid, first_name, last_name, url, element FROM superpixel_visitors LIMIT 5;"
```

### **Webhook Testing**
```bash
# Test with URL parameter (RECOMMENDED)
curl -X POST "https://hook.thynkdata.com/pixel_import.php?client=CLIENT_NAME" \
-H "Content-Type: application/json" \
-d '{"resolution":[{"UUID":"test","FIRST_NAME":"John","LAST_NAME":"Doe","visited_url":"https://example.com","element":"Button","percentage":85,"referrer":"https://google.com","timestamp":"'$(date +%s)'","event_type":"click","ip_address":"192.168.1.1"}]}'
```

## 🔍 **Troubleshooting**

### **Common Issues**
1. **Webhook shows "client: none"** - Use URL parameter `?client=DATABASE_NAME`
2. **Visitors not created** - Check webhook logs for variable reference errors
3. **Sheets not syncing** - Run `php force_sync.php` to force immediate sync
4. **API not responding** - Check `pm2 status` and restart if needed

### **Debug Steps**
1. Check all log files for errors
2. Verify database connectivity
3. Test webhook with URL parameter
4. Force sync to check data flow
5. Restart services if needed

## 📈 **Performance**

### **Sync Frequency**
- **Smart Sync**: Every 5 minutes (staggered across sheets)
- **Monitor**: Continuous (detects new sheets within seconds)
- **Force Sync**: Manual trigger (immediate, all sheets)

### **Data Flow**
1. Pixel generates event → Webhook receives data
2. Webhook saves to database → Monitor detects new data
3. Smart sync updates Google Sheets → Data appears in sheets

## 🔐 **Security**

### **Authentication**
- Google Sheets API with OAuth delegation
- Database access with encrypted credentials
- Webhook validation and sanitization

### **Data Protection**
- All user data encrypted in transit
- Database connections use SSL
- Input validation and SQL injection prevention

## 📞 **Support**

For issues or questions:
1. Check logs first
2. Run force sync to test data flow
3. Verify webhook with URL parameter
4. Check service status with `pm2 status`

---

**Last Updated**: July 26, 2025
**Version**: 1.0.0 