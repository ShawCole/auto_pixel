#!/bin/bash

echo "🚀 Setting up Pixel Setup App..."

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    echo "❌ Node.js is not installed. Please install Node.js 18+ first."
    exit 1
fi

# Check Node.js version
NODE_VERSION=$(node -v | cut -d'v' -f2 | cut -d'.' -f1)
if [ "$NODE_VERSION" -lt 18 ]; then
    echo "❌ Node.js version 18+ is required. Current version: $(node -v)"
    exit 1
fi

echo "✅ Node.js version: $(node -v)"

# Check if pnpm is installed, if not install it
if ! command -v pnpm &> /dev/null; then
    echo "📦 Installing pnpm..."
    npm install -g pnpm
fi

echo "✅ pnpm version: $(pnpm --version)"

# Install dependencies
echo "📦 Installing dependencies..."
pnpm install

# Create .env file if it doesn't exist
if [ ! -f .env ]; then
    echo "📝 Creating .env file..."
    cat > .env << EOF
# Database Configuration
DB_HOST=34.31.66.104
DB_USER=root
DB_PASS=AccuPoint01!
TEMPLATE_DB=pixel
TEMPLATE_TABLE=superpixel_resolution_log

# AudienceLab Credentials
AUDLAB_USERNAME=shaw@accupointsolutions.com
AUDLAB_PASSWORD=AccuPoint01!

# Server Configuration
PORT=4000
EOF
    echo "✅ .env file created. Please review and update credentials if needed."
else
    echo "✅ .env file already exists."
fi

# Check if Chrome is installed (for Selenium)
if command -v google-chrome &> /dev/null || command -v chromium-browser &> /dev/null || command -v chrome &> /dev/null; then
    echo "✅ Chrome browser found."
else
    echo "⚠️  Chrome browser not found. Selenium automation may not work."
    echo "   Please install Google Chrome for full functionality."
fi

echo ""
echo "🎉 Setup complete!"
echo ""
echo "Next steps:"
echo "1. Review and update the .env file with your credentials"
echo "2. Run 'pnpm dev' to start both frontend and backend"
echo "3. Open http://localhost:5173 in your browser"
echo ""
echo "Happy pixel generating! 🎯" 