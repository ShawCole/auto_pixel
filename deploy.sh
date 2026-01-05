#!/bin/bash

# Auto Pixel Deployment Script for Google Cloud Platform
# This script deploys the Node.js backend alongside existing PHP setup

set -e

echo "🚀 Starting Auto Pixel deployment to Google Cloud Platform..."

# Configuration
PROJECT_ID="your-gcp-project-id"  # Replace with your actual project ID
INSTANCE_NAME="your-vm-instance"   # Replace with your actual VM instance name
ZONE="us-central1-a"              # Replace with your actual zone
REGION="us-central1"              # Replace with your actual region

# Application configuration
APP_NAME="auto-pixel-backend"
APP_PORT=4000
SERVICE_USER="auto-pixel"
SERVICE_DIR="/opt/auto-pixel"
LOG_DIR="/var/log/auto-pixel"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running on the target VM
if [ "$(hostname)" != "$INSTANCE_NAME" ]; then
    print_warning "This script should be run on the target VM: $INSTANCE_NAME"
    print_status "You can run this script locally to copy files to the VM, or SSH into the VM and run it there."
    
    read -p "Do you want to copy files to the VM and then SSH to run deployment? (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        print_status "Copying files to VM..."
        gcloud compute scp --recurse . $INSTANCE_NAME:~/auto-pixel-deploy --zone=$ZONE
        
        print_status "SSH into VM to continue deployment..."
        gcloud compute ssh $INSTANCE_NAME --zone=$ZONE --command="cd ~/auto-pixel-deploy && chmod +x deploy.sh && ./deploy.sh"
        exit 0
    else
        print_error "Please run this script on the target VM or use the copy option."
        exit 1
    fi
fi

print_status "Running deployment on $(hostname)..."

# Update system packages
print_status "Updating system packages..."
sudo apt-get update
sudo apt-get upgrade -y

# Install Node.js and npm if not already installed
if ! command -v node &> /dev/null; then
    print_status "Installing Node.js..."
    curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
    sudo apt-get install -y nodejs
else
    print_status "Node.js already installed: $(node --version)"
fi

# Install PM2 for process management
if ! command -v pm2 &> /dev/null; then
    print_status "Installing PM2..."
    sudo npm install -g pm2
else
    print_status "PM2 already installed: $(pm2 --version)"
fi

# Create service user
if ! id "$SERVICE_USER" &>/dev/null; then
    print_status "Creating service user: $SERVICE_USER"
    sudo useradd -r -s /bin/false -d $SERVICE_DIR $SERVICE_USER
else
    print_status "Service user $SERVICE_USER already exists"
fi

# Create application directory
print_status "Setting up application directory..."
sudo mkdir -p $SERVICE_DIR
sudo mkdir -p $LOG_DIR
sudo chown $SERVICE_USER:$SERVICE_USER $SERVICE_DIR
sudo chown $SERVICE_USER:$SERVICE_USER $LOG_DIR

# Copy application files
print_status "Copying application files..."
if [ -d "~/auto-pixel-deploy" ]; then
    sudo cp -r ~/auto-pixel-deploy/* $SERVICE_DIR/
else
    sudo cp -r . $SERVICE_DIR/
fi

# Set proper permissions
sudo chown -R $SERVICE_USER:$SERVICE_USER $SERVICE_DIR
sudo chmod +x $SERVICE_DIR/deploy.sh

# Install dependencies
print_status "Installing Node.js dependencies..."
cd $SERVICE_DIR/server
sudo -u $SERVICE_USER npm install
sudo -u $SERVICE_USER npm run build

# Create environment file
print_status "Creating environment configuration..."
sudo tee $SERVICE_DIR/server/.env > /dev/null <<EOF
# Database Configuration
DB_HOST=34.26.61.148
DB_USER=root
DB_PASS=AccuPoint01!
TEMPLATE_DB=pixel
TEMPLATE_TABLE=pixel_data

# AudienceLab Configuration
AUDLAB_USERNAME=your_audlab_username
AUDLAB_PASSWORD=your_audlab_password

# Application Configuration
NODE_ENV=production
PORT=$APP_PORT
DEBUG=

# Logging
LOG_LEVEL=info
EOF

sudo chown $SERVICE_USER:$SERVICE_USER $SERVICE_DIR/server/.env

# Create PM2 ecosystem file
print_status "Creating PM2 configuration..."
sudo tee $SERVICE_DIR/ecosystem.config.js > /dev/null <<EOF
module.exports = {
  apps: [{
    name: '$APP_NAME',
    script: '$SERVICE_DIR/server/dist/index.js',
    cwd: '$SERVICE_DIR/server',
    user: '$SERVICE_USER',
    instances: 1,
    autorestart: true,
    watch: false,
    max_memory_restart: '1G',
    env: {
      NODE_ENV: 'production',
      PORT: $APP_PORT
    },
    error_file: '$LOG_DIR/err.log',
    out_file: '$LOG_DIR/out.log',
    log_file: '$LOG_DIR/combined.log',
    time: true
  }]
};
EOF

sudo chown $SERVICE_USER:$SERVICE_USER $SERVICE_DIR/ecosystem.config.js

# Create systemd service (alternative to PM2)
print_status "Creating systemd service..."
sudo tee /etc/systemd/system/$APP_NAME.service > /dev/null <<EOF
[Unit]
Description=Auto Pixel Backend
After=network.target

[Service]
Type=simple
User=$SERVICE_USER
WorkingDirectory=$SERVICE_DIR/server
ExecStart=/usr/bin/node dist/index.js
Restart=always
RestartSec=10
Environment=NODE_ENV=production
Environment=PORT=$APP_PORT
StandardOutput=append:$LOG_DIR/service.log
StandardError=append:$LOG_DIR/service.log

[Install]
WantedBy=multi-user.target
EOF

# Create Nginx configuration
print_status "Creating Nginx configuration..."
sudo tee /etc/nginx/sites-available/$APP_NAME > /dev/null <<EOF
server {
    listen 80;
    server_name api.thynkdata.com;  # Replace with your domain

    location / {
        proxy_pass http://localhost:$APP_PORT;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_cache_bypass \$http_upgrade;
    }

    # Health check endpoint
    location /health {
        proxy_pass http://localhost:$APP_PORT/health;
        access_log off;
    }
}
EOF

# Enable Nginx site
if [ -f "/etc/nginx/sites-available/$APP_NAME" ]; then
    sudo ln -sf /etc/nginx/sites-available/$APP_NAME /etc/nginx/sites-enabled/
    sudo nginx -t
    sudo systemctl reload nginx
fi

# Start the application with PM2
print_status "Starting application with PM2..."
cd $SERVICE_DIR
sudo -u $SERVICE_USER pm2 start ecosystem.config.js
sudo -u $SERVICE_USER pm2 save
sudo -u $SERVICE_USER pm2 startup

# Alternative: Start with systemd
# sudo systemctl enable $APP_NAME
# sudo systemctl start $APP_NAME

# Create firewall rules (if using GCP firewall)
print_status "Setting up firewall rules..."
gcloud compute firewall-rules create allow-auto-pixel-api \
    --allow tcp:$APP_PORT \
    --source-ranges 0.0.0.0/0 \
    --description "Allow Auto Pixel API traffic" \
    --project=$PROJECT_ID || print_warning "Firewall rule may already exist"

# Create monitoring script
print_status "Creating monitoring script..."
sudo tee $SERVICE_DIR/monitor.sh > /dev/null <<EOF
#!/bin/bash
# Auto Pixel Monitoring Script

LOG_FILE="$LOG_DIR/monitor.log"
APP_STATUS=\$(pm2 jlist | jq -r '.[] | select(.name=="$APP_NAME") | .pm2_env.status')

echo "\$(date): Application status: \$APP_STATUS" >> \$LOG_FILE

if [ "\$APP_STATUS" != "online" ]; then
    echo "\$(date): Application is down! Restarting..." >> \$LOG_FILE
    pm2 restart $APP_NAME
    # Send notification (you can add email/Slack notification here)
fi
EOF

sudo chmod +x $SERVICE_DIR/monitor.sh
sudo chown $SERVICE_USER:$SERVICE_USER $SERVICE_DIR/monitor.sh

# Add monitoring to crontab
(crontab -l 2>/dev/null; echo "*/5 * * * * $SERVICE_DIR/monitor.sh") | crontab -

# Create backup script
print_status "Creating backup script..."
sudo tee $SERVICE_DIR/backup.sh > /dev/null <<EOF
#!/bin/bash
# Auto Pixel Backup Script

BACKUP_DIR="/var/backups/auto-pixel"
DATE=\$(date +%Y%m%d_%H%M%S)

mkdir -p \$BACKUP_DIR

# Backup application files
tar -czf \$BACKUP_DIR/app_\$DATE.tar.gz -C $SERVICE_DIR .

# Backup logs
tar -czf \$BACKUP_DIR/logs_\$DATE.tar.gz -C $LOG_DIR .

# Keep only last 7 days of backups
find \$BACKUP_DIR -name "*.tar.gz" -mtime +7 -delete

echo "Backup completed: \$DATE"
EOF

sudo chmod +x $SERVICE_DIR/backup.sh
sudo chown $SERVICE_USER:$SERVICE_USER $SERVICE_DIR/backup.sh

# Add daily backup to crontab
(crontab -l 2>/dev/null; echo "0 2 * * * $SERVICE_DIR/backup.sh") | crontab -

print_status "Deployment completed successfully!"
print_status "Application is running on port $APP_PORT"
print_status "PM2 status:"
sudo -u $SERVICE_USER pm2 status

print_status "Useful commands:"
echo "  View logs: sudo -u $SERVICE_USER pm2 logs $APP_NAME"
echo "  Restart app: sudo -u $SERVICE_USER pm2 restart $APP_NAME"
echo "  Stop app: sudo -u $SERVICE_USER pm2 stop $APP_NAME"
echo "  Monitor: tail -f $LOG_DIR/out.log"
echo "  Health check: curl http://localhost:$APP_PORT/health"

print_status "Next steps:"
echo "  1. Update the .env file with your actual credentials"
echo "  2. Configure your domain DNS to point to this server"
echo "  3. Set up SSL certificate with Let's Encrypt"
echo "  4. Test the API endpoints"

print_status "🚀 Auto Pixel is now deployed and running!" 