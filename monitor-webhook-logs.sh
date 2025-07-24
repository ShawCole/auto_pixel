#!/bin/bash

# Monitor webhook logs in real-time
echo "🔍 Webhook Log Monitor"
echo "====================="

# Server details
SERVER_USER="root"
SERVER_HOST="your-webhook-server.com"
REMOTE_PATH="/var/www/hook.thynkdata.com/"

# Function to show menu
show_menu() {
    echo ""
    echo "Select log to monitor:"
    echo "1) webhook_debug_full.log - Full debug details"
    echo "2) webhook_simple.log - Simple one-line logs"
    echo "3) pixel_import_debug.log - Original webhook logs"
    echo "4) Show last 50 lines of all logs"
    echo "5) Clear all log files"
    echo "6) Exit"
    echo ""
    read -p "Choice: " choice
}

while true; do
    show_menu
    
    case $choice in
        1)
            echo "📝 Monitoring full debug log (Ctrl+C to stop)..."
            ssh ${SERVER_USER}@${SERVER_HOST} "tail -f ${REMOTE_PATH}webhook_debug_full.log"
            ;;
        2)
            echo "📝 Monitoring simple log (Ctrl+C to stop)..."
            ssh ${SERVER_USER}@${SERVER_HOST} "tail -f ${REMOTE_PATH}webhook_simple.log"
            ;;
        3)
            echo "📝 Monitoring original webhook log (Ctrl+C to stop)..."
            ssh ${SERVER_USER}@${SERVER_HOST} "tail -f ${REMOTE_PATH}pixel_import_debug.log"
            ;;
        4)
            echo "📄 Last 50 lines of all logs:"
            echo ""
            echo "=== webhook_debug_full.log ==="
            ssh ${SERVER_USER}@${SERVER_HOST} "tail -n 50 ${REMOTE_PATH}webhook_debug_full.log 2>/dev/null || echo 'Log file not found'"
            echo ""
            echo "=== webhook_simple.log ==="
            ssh ${SERVER_USER}@${SERVER_HOST} "tail -n 50 ${REMOTE_PATH}webhook_simple.log 2>/dev/null || echo 'Log file not found'"
            echo ""
            echo "=== pixel_import_debug.log ==="
            ssh ${SERVER_USER}@${SERVER_HOST} "tail -n 50 ${REMOTE_PATH}pixel_import_debug.log 2>/dev/null || echo 'Log file not found'"
            ;;
        5)
            read -p "Are you sure you want to clear all log files? (y/N): " confirm
            if [[ $confirm == "y" || $confirm == "Y" ]]; then
                ssh ${SERVER_USER}@${SERVER_HOST} "cd ${REMOTE_PATH} && > webhook_debug_full.log && > webhook_simple.log && > pixel_import_debug.log"
                echo "✅ Log files cleared"
            fi
            ;;
        6)
            echo "Goodbye!"
            exit 0
            ;;
        *)
            echo "Invalid choice"
            ;;
    esac
done 