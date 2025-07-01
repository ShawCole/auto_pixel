#!/bin/bash

# Quick Auto Pixel Deployment Script
# Run this directly on your Google Cloud VM

set -e

echo "🚀 Quick deployment of Auto Pixel Backend..."

# Configuration
APP_DIR="/opt/auto-pixel"
SERVICE_USER="auto-pixel"
APP_PORT=4000

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Update system
print_status "Updating system packages..."
sudo apt-get update -y

# Install Node.js if not installed
if ! command -v node &> /dev/null; then
    print_status "Installing Node.js 18..."
    curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
    sudo apt-get install -y nodejs
fi

# Install PM2
if ! command -v pm2 &> /dev/null; then
    print_status "Installing PM2..."
    sudo npm install -g pm2
fi

# Install Chrome dependencies for Selenium
print_status "Installing Chrome dependencies..."
sudo apt-get install -y wget gnupg
wget -q -O - https://dl.google.com/linux/linux_signing_key.pub | sudo apt-key add -
echo "deb [arch=amd64] http://dl.google.com/linux/chrome/deb/ stable main" | sudo tee /etc/apt/sources.list.d/google-chrome.list
sudo apt-get update
sudo apt-get install -y google-chrome-stable

# Create service user
if ! id "$SERVICE_USER" &>/dev/null; then
    print_status "Creating service user..."
    sudo useradd -r -s /bin/false -d $APP_DIR $SERVICE_USER
fi

# Create directories
print_status "Setting up directories..."
sudo mkdir -p $APP_DIR
sudo mkdir -p /var/log/auto-pixel

# Clone repository
print_status "Cloning repository..."
if [ -d "$APP_DIR" ]; then
    sudo rm -rf $APP_DIR/*
fi
git clone https://github.com/ShawCole/auto_pixel.git /tmp/auto-pixel
sudo cp -r /tmp/auto-pixel/* $APP_DIR/
sudo chown -R $SERVICE_USER:$SERVICE_USER $APP_DIR

# Install dependencies
print_status "Installing dependencies..."
cd $APP_DIR/server
sudo -u $SERVICE_USER npm install

# Build TypeScript
print_status "Building application..."
sudo -u $SERVICE_USER npm run build

# Create environment file (you'll need to update credentials)
print_status "Creating environment file..."
sudo tee $APP_DIR/server/.env > /dev/null <<EOF
# Database Configuration
DB_HOST=34.31.66.104
DB_USER=root
DB_PASS=AccuPoint01!
TEMPLATE_DB=template
TEMPLATE_TABLE=superpixel_resolution_log

# AudienceLab Configuration - UPDATE THESE!
AUDLAB_USERNAME=your_actual_username
AUDLAB_PASSWORD=your_actual_password

# Application Configuration
NODE_ENV=production
PORT=$APP_PORT
DEBUG=
EOF

sudo chown $SERVICE_USER:$SERVICE_USER $APP_DIR/server/.env

# Create PM2 configuration
print_status "Creating PM2 configuration..."
sudo tee $APP_DIR/ecosystem.config.js > /dev/null <<EOF
module.exports = {
  apps: [{
    name: 'auto-pixel-backend',
    script: 'dist/index.js',
    cwd: '$APP_DIR/server',
    instances: 1,
    autorestart: true,
    watch: false,
    max_memory_restart: '1G',
    env: {
      NODE_ENV: 'production',
      PORT: $APP_PORT
    },
    error_file: '/var/log/auto-pixel/err.log',
    out_file: '/var/log/auto-pixel/out.log',
    log_file: '/var/log/auto-pixel/combined.log',
    time: true
  }]
};
EOF

# Set ownership
sudo chown -R $SERVICE_USER:$SERVICE_USER $APP_DIR
sudo chown -R $SERVICE_USER:$SERVICE_USER /var/log/auto-pixel

# Start application
print_status "Starting application..."
cd $APP_DIR
sudo -u $SERVICE_USER pm2 start ecosystem.config.js
sudo -u $SERVICE_USER pm2 save

# Setup PM2 startup
sudo env PATH=$PATH:/usr/bin /usr/lib/node_modules/pm2/bin/pm2 startup systemd -u $SERVICE_USER --hp /opt/auto-pixel

print_status "✅ Deployment completed!"
print_status "Application is running on port $APP_PORT"
print_status ""
print_status "Next steps:"
echo "1. Update credentials in: $APP_DIR/server/.env"
echo "2. Open firewall port: sudo ufw allow $APP_PORT"
echo "3. Test health: curl http://localhost:$APP_PORT/health"
echo ""
print_status "Management commands:"
echo "View logs: sudo -u $SERVICE_USER pm2 logs"
echo "Restart: sudo -u $SERVICE_USER pm2 restart auto-pixel-backend"
echo "Status: sudo -u $SERVICE_USER pm2 status"

print_warning "⚠️  IMPORTANT: Update the AudienceLab credentials in the .env file!" 