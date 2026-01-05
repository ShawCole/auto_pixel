# Auto Pixel Deployment Guide for Google Cloud Platform

This guide will help you deploy your Auto Pixel application to Google Cloud Platform alongside your existing PHP setup.

## Prerequisites

- Google Cloud Platform account with billing enabled
- Google Cloud SDK installed locally
- Access to your existing VM instance
- Domain name (optional, for SSL)

## Deployment Options

### Option 1: Traditional Deployment (Recommended for existing VM)

Use the `deploy.sh` script for a traditional deployment that installs Node.js directly on your VM.

### Option 2: Docker Deployment (Recommended for new deployments)

Use the `docker-deploy.sh` script for a containerized deployment using Docker.

## Step-by-Step Deployment

### 1. Prepare Your Local Environment

First, make sure you have the Google Cloud SDK installed and configured:

```bash
# Install Google Cloud SDK (if not already installed)
curl https://sdk.cloud.google.com | bash
exec -l $SHELL

# Authenticate with Google Cloud
gcloud auth login
gcloud config set project YOUR_PROJECT_ID
```

### 2. Update Configuration

Before deploying, update the configuration in your deployment script:

**For `deploy.sh`:**
```bash
# Edit deploy.sh and update these variables:
PROJECT_ID="your-gcp-project-id"
INSTANCE_NAME="your-vm-instance"
ZONE="us-central1-a"
REGION="us-central1"
```

**For `docker-deploy.sh`:**
```bash
# Edit docker-deploy.sh and update these variables:
PROJECT_ID="your-gcp-project-id"
INSTANCE_NAME="your-vm-instance"
ZONE="us-central1-a"
```

### 3. Deploy the Application

#### Option A: Traditional Deployment

```bash
# Make the script executable
chmod +x deploy.sh

# Run the deployment script
./deploy.sh
```

The script will:
- Copy files to your VM
- SSH into the VM
- Install Node.js and dependencies
- Set up PM2 for process management
- Configure Nginx as a reverse proxy
- Set up monitoring and backup scripts

#### Option B: Docker Deployment

```bash
# Make the script executable
chmod +x docker-deploy.sh

# Run the deployment script
./docker-deploy.sh
```

The script will:
- Copy files to your VM
- SSH into the VM
- Install Docker and Docker Compose
- Build and run the application in containers
- Set up monitoring and backup scripts

### 4. Configure Environment Variables

After deployment, update the environment configuration:

**For traditional deployment:**
```bash
# SSH into your VM
gcloud compute ssh YOUR_INSTANCE_NAME --zone=YOUR_ZONE

# Edit the environment file
sudo nano /opt/auto-pixel/server/.env
```

**For Docker deployment:**
```bash
# SSH into your VM
gcloud compute ssh YOUR_INSTANCE_NAME --zone=YOUR_ZONE

# Edit the environment file
nano /opt/auto-pixel-docker/.env
```

Update the following variables:
```bash
# AudienceLab Configuration
AUDLAB_USERNAME=your_actual_username
AUDLAB_PASSWORD=your_actual_password

# Database Configuration (already configured)
DB_HOST=34.26.61.148
DB_USER=root
DB_PASS=AccuPoint01!
TEMPLATE_DB=pixel
TEMPLATE_TABLE=pixel_data
```

### 5. Configure Domain and SSL (Optional)

#### Set up DNS

Point your domain to your VM's external IP:
```bash
# Get your VM's external IP
gcloud compute instances describe YOUR_INSTANCE_NAME --zone=YOUR_ZONE --format="get(networkInterfaces[0].accessConfigs[0].natIP)"
```

#### Set up SSL with Let's Encrypt

```bash
# SSH into your VM
gcloud compute ssh YOUR_INSTANCE_NAME --zone=YOUR_ZONE

# Install Certbot
sudo apt-get install certbot python3-certbot-nginx

# Get SSL certificate
sudo certbot --nginx -d api.thynkdata.com

# Test automatic renewal
sudo certbot renew --dry-run
```

### 6. Test the Deployment

Test your API endpoints:

```bash
# Health check
curl http://your-domain.com/health

# Generate a pixel (replace with your actual client name)
curl -X POST http://your-domain.com/generate \
  -H "Content-Type: application/json" \
  -d '{"client": "test_client", "website": "https://example.com"}'
```

## Management Commands

### Traditional Deployment

```bash
# View application status
sudo -u auto-pixel pm2 status

# View logs
sudo -u auto-pixel pm2 logs auto-pixel-backend

# Restart application
sudo -u auto-pixel pm2 restart auto-pixel-backend

# Stop application
sudo -u auto-pixel pm2 stop auto-pixel-backend

# Monitor logs
tail -f /var/log/auto-pixel/out.log
```

### Docker Deployment

```bash
# View container status
docker-compose ps

# View logs
docker-compose logs -f auto-pixel-backend

# Restart application
docker-compose restart auto-pixel-backend

# Stop application
docker-compose down

# Update application
./update.sh
```

## Monitoring and Maintenance

### Automatic Monitoring

Both deployment options include automatic monitoring:
- Health checks every 5 minutes
- Automatic restart on failure
- Log rotation and management

### Backup Strategy

Automatic daily backups are configured:
- Application files
- Logs
- Database (if local)

### Update Process

#### Traditional Deployment
```bash
# SSH into VM
gcloud compute ssh YOUR_INSTANCE_NAME --zone=YOUR_ZONE

# Update application
cd /opt/auto-pixel
git pull origin main  # if using git
sudo -u auto-pixel npm install
sudo -u auto-pixel npm run build
sudo -u auto-pixel pm2 restart auto-pixel-backend
```

#### Docker Deployment
```bash
# SSH into VM
gcloud compute ssh YOUR_INSTANCE_NAME --zone=YOUR_ZONE

# Update application
cd /opt/auto-pixel-docker
./update.sh
```

## Troubleshooting

### Common Issues

1. **Application won't start**
   - Check logs: `pm2 logs` or `docker-compose logs`
   - Verify environment variables
   - Check database connectivity

2. **Port already in use**
   - Check what's using port 4000: `sudo netstat -tlnp | grep :4000`
   - Stop conflicting service or change port

3. **Permission issues**
   - Ensure proper file ownership: `sudo chown -R auto-pixel:auto-pixel /opt/auto-pixel`
   - Check service user permissions

4. **Database connection issues**
   - Verify database credentials
   - Check firewall rules
   - Test connection manually

### Log Locations

**Traditional Deployment:**
- Application logs: `/var/log/auto-pixel/`
- PM2 logs: `pm2 logs`
- Nginx logs: `/var/log/nginx/`

**Docker Deployment:**
- Application logs: `/opt/auto-pixel-docker/logs/`
- Container logs: `docker-compose logs`
- Nginx logs: `/opt/auto-pixel-docker/logs/nginx/`

### Getting Help

If you encounter issues:

1. Check the logs first
2. Verify all environment variables are set
3. Test database connectivity
4. Check firewall and network settings
5. Review the deployment script output for errors

## Security Considerations

1. **Environment Variables**: Never commit sensitive data to version control
2. **Firewall Rules**: Only open necessary ports
3. **SSL/TLS**: Always use HTTPS in production
4. **Regular Updates**: Keep the system and dependencies updated
5. **Monitoring**: Set up alerts for application failures

## Cost Optimization

1. **Use appropriate machine types** for your workload
2. **Enable auto-scaling** if needed
3. **Use preemptible instances** for non-critical workloads
4. **Monitor usage** with Google Cloud Console
5. **Set up billing alerts** to avoid unexpected charges

## Next Steps

After successful deployment:

1. Set up monitoring and alerting
2. Configure log aggregation
3. Set up CI/CD pipeline for automated deployments
4. Implement backup verification
5. Create runbooks for common issues
6. Set up performance monitoring 