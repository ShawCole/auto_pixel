#!/bin/bash

# Docker-based deployment script for Auto Pixel
# This script deploys the application using Docker and Docker Compose

set -e

echo "🐳 Starting Docker-based Auto Pixel deployment..."

# Configuration
PROJECT_ID="your-gcp-project-id"  # Replace with your actual project ID
INSTANCE_NAME="your-vm-instance"   # Replace with your actual VM instance name
ZONE="us-central1-a"              # Replace with your actual zone

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

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
        gcloud compute scp --recurse . $INSTANCE_NAME:~/auto-pixel-docker --zone=$ZONE
        
        print_status "SSH into VM to continue deployment..."
        gcloud compute ssh $INSTANCE_NAME --zone=$ZONE --command="cd ~/auto-pixel-docker && chmod +x docker-deploy.sh && ./docker-deploy.sh"
        exit 0
    else
        print_error "Please run this script on the target VM or use the copy option."
        exit 1
    fi
fi

print_status "Running Docker deployment on $(hostname)..."

# Update system packages
print_status "Updating system packages..."
sudo apt-get update
sudo apt-get upgrade -y

# Install Docker if not already installed
if ! command -v docker &> /dev/null; then
    print_status "Installing Docker..."
    curl -fsSL https://get.docker.com -o get-docker.sh
    sudo sh get-docker.sh
    sudo usermod -aG docker $USER
    rm get-docker.sh
    print_warning "Docker installed. You may need to log out and back in for group changes to take effect."
else
    print_status "Docker already installed: $(docker --version)"
fi

# Install Docker Compose if not already installed
if ! command -v docker-compose &> /dev/null; then
    print_status "Installing Docker Compose..."
    sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
    sudo chmod +x /usr/local/bin/docker-compose
else
    print_status "Docker Compose already installed: $(docker-compose --version)"
fi

# Create application directory
APP_DIR="/opt/auto-pixel-docker"
print_status "Setting up application directory: $APP_DIR"
sudo mkdir -p $APP_DIR
sudo mkdir -p $APP_DIR/logs
sudo mkdir -p $APP_DIR/backups
sudo mkdir -p $APP_DIR/ssl

# Copy application files
print_status "Copying application files..."
if [ -d "~/auto-pixel-docker" ]; then
    sudo cp -r ~/auto-pixel-docker/* $APP_DIR/
else
    sudo cp -r . $APP_DIR/
fi

# Set proper permissions
sudo chown -R $USER:$USER $APP_DIR
sudo chmod +x $APP_DIR/docker-deploy.sh

# Create environment file
print_status "Creating environment configuration..."
cat > $APP_DIR/.env <<EOF
# AudienceLab Configuration
AUDLAB_USERNAME=your_audlab_username
AUDLAB_PASSWORD=your_audlab_password

# Database Configuration (these are already in docker-compose.yml)
DB_HOST=34.31.66.104
DB_USER=root
DB_PASS=AccuPoint01!
TEMPLATE_DB=pixel
TEMPLATE_TABLE=pixel_data
EOF

print_warning "Please update the .env file with your actual AudienceLab credentials!"

# Build and start the application
print_status "Building and starting Docker containers..."
cd $APP_DIR

# Stop any existing containers
docker-compose down || true

# Build the application
print_status "Building Docker image..."
docker-compose build --no-cache

# Start the application
print_status "Starting containers..."
docker-compose up -d

# Wait for the application to be ready
print_status "Waiting for application to be ready..."
sleep 30

# Check if the application is running
if curl -f http://localhost:4000/health > /dev/null 2>&1; then
    print_status "✅ Application is running successfully!"
else
    print_error "❌ Application failed to start properly"
    print_status "Checking container logs..."
    docker-compose logs auto-pixel-backend
    exit 1
fi

# Create firewall rules (if using GCP firewall)
print_status "Setting up firewall rules..."
gcloud compute firewall-rules create allow-auto-pixel-api \
    --allow tcp:4000 \
    --source-ranges 0.0.0.0/0 \
    --description "Allow Auto Pixel API traffic" \
    --project=$PROJECT_ID || print_warning "Firewall rule may already exist"

# Create monitoring script
print_status "Creating monitoring script..."
cat > $APP_DIR/monitor.sh <<'EOF'
#!/bin/bash
# Auto Pixel Docker Monitoring Script

LOG_FILE="/opt/auto-pixel-docker/logs/monitor.log"
APP_DIR="/opt/auto-pixel-docker"

cd $APP_DIR

# Check if containers are running
if ! docker-compose ps | grep -q "Up"; then
    echo "$(date): Containers are down! Restarting..." >> $LOG_FILE
    docker-compose up -d
    # Send notification (you can add email/Slack notification here)
fi

# Check application health
if ! curl -f http://localhost:4000/health > /dev/null 2>&1; then
    echo "$(date): Application health check failed! Restarting..." >> $LOG_FILE
    docker-compose restart auto-pixel-backend
fi
EOF

chmod +x $APP_DIR/monitor.sh

# Add monitoring to crontab
(crontab -l 2>/dev/null; echo "*/5 * * * * $APP_DIR/monitor.sh") | crontab -

# Create backup script
print_status "Creating backup script..."
cat > $APP_DIR/backup.sh <<'EOF'
#!/bin/bash
# Auto Pixel Docker Backup Script

APP_DIR="/opt/auto-pixel-docker"
BACKUP_DIR="/opt/auto-pixel-docker/backups"
DATE=$(date +%Y%m%d_%H%M%S)

cd $APP_DIR

# Create backup directory
mkdir -p $BACKUP_DIR

# Backup application files
tar -czf $BACKUP_DIR/app_$DATE.tar.gz \
    --exclude=node_modules \
    --exclude=dist \
    --exclude=logs \
    --exclude=backups \
    .

# Backup logs
tar -czf $BACKUP_DIR/logs_$DATE.tar.gz logs/

# Backup Docker volumes (if any)
docker run --rm -v auto-pixel-docker_logs:/data -v $BACKUP_DIR:/backup alpine tar -czf /backup/docker_logs_$DATE.tar.gz -C /data .

# Keep only last 7 days of backups
find $BACKUP_DIR -name "*.tar.gz" -mtime +7 -delete

echo "Backup completed: $DATE"
EOF

chmod +x $APP_DIR/backup.sh

# Add daily backup to crontab
(crontab -l 2>/dev/null; echo "0 2 * * * $APP_DIR/backup.sh") | crontab -

# Create update script
print_status "Creating update script..."
cat > $APP_DIR/update.sh <<'EOF'
#!/bin/bash
# Auto Pixel Docker Update Script

APP_DIR="/opt/auto-pixel-docker"
LOG_FILE="$APP_DIR/logs/update.log"

cd $APP_DIR

echo "$(date): Starting application update..." >> $LOG_FILE

# Pull latest changes (if using git)
# git pull origin main

# Stop containers
docker-compose down

# Rebuild and start
docker-compose build --no-cache
docker-compose up -d

# Wait for startup
sleep 30

# Check health
if curl -f http://localhost:4000/health > /dev/null 2>&1; then
    echo "$(date): Update completed successfully!" >> $LOG_FILE
else
    echo "$(date): Update failed! Rolling back..." >> $LOG_FILE
    docker-compose down
    docker-compose up -d
fi
EOF

chmod +x $APP_DIR/update.sh

print_status "Deployment completed successfully!"
print_status "Application is running on port 4000"
print_status "Container status:"
docker-compose ps

print_status "Useful commands:"
echo "  View logs: docker-compose logs -f auto-pixel-backend"
echo "  Restart app: docker-compose restart auto-pixel-backend"
echo "  Stop app: docker-compose down"
echo "  Update app: $APP_DIR/update.sh"
echo "  Monitor: tail -f $APP_DIR/logs/monitor.log"
echo "  Health check: curl http://localhost:4000/health"

print_status "Next steps:"
echo "  1. Update the .env file with your actual credentials"
echo "  2. Configure your domain DNS to point to this server"
echo "  3. Set up SSL certificate with Let's Encrypt"
echo "  4. Test the API endpoints"

print_status "🐳 Auto Pixel is now deployed with Docker and running!" 