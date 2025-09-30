#!/usr/bin/env bash
set -euo pipefail

# ---------- edit if needed ----------
ACCOUNT="scole@thynkdata.com"
PROJECT="thynk-intent-dev-463522"
ZONE="us-central1-c"
INSTANCE="pixel-php"

LOCAL_PHP="./pixel_import.php"
HOOK_PATH="/var/www/hook.thynkdata.com/pixel_import.php"
API_PATH="/opt/auto-pixel/pixel_import.php"

WEBHOOK_URL="https://hook.thynkdata.com/pixel_import.php?client=warp_probe"
CURL="curl -fsS --max-time 10"
# -----------------------------------

echo "[INFO] Ensuring correct gcloud context"
gcloud config set account "$ACCOUNT" >/dev/null
gcloud config set project "$PROJECT" >/dev/null

echo "[INFO] Confirming local file exists: $LOCAL_PHP"
[[ -f "$LOCAL_PHP" ]] || { echo "[FAIL] $LOCAL_PHP not found"; exit 1; }

echo "[INFO] Uploading updated pixel_import.php to VM home"
gcloud compute scp --tunnel-through-iap --zone "$ZONE" "$LOCAL_PHP" "$INSTANCE:~/pixel_import.php"

echo "[INFO] Deploying on VM with backups + perms"
cat <<'REMOTE' | gcloud compute ssh "$INSTANCE" --zone "$ZONE" --tunnel-through-iap -- -T 'bash -s'
set -euo pipefail
TS="$(date +%F-%H%M%S)"
SRC=""
# Uploaded path can be under invoking user or sudo user; try both:
if [[ -f "/home/$USER/pixel_import.php" ]]; then SRC="/home/$USER/pixel_import.php"; fi
if [[ -z "$SRC" && -n "${SUDO_USER:-}" && -f "/home/$SUDO_USER/pixel_import.php" ]]; then SRC="/home/$SUDO_USER/pixel_import.php"; fi
[[ -n "$SRC" ]] || { echo "[FAIL] Uploaded file not found in ~"; exit 1; }

HOOK_PATH="/var/www/hook.thynkdata.com/pixel_import.php"
API_PATH="/opt/auto-pixel/pixel_import.php"

# Backups (only if files exist) - using sudo for web server directories
for P in "$HOOK_PATH" "$API_PATH"; do
  if [[ -f "$P" ]]; then
    sudo cp -a "$P" "${P}.bak.$TS"
    echo "[INFO] Backup created: ${P}.bak.$TS"
  fi
done

# Install new file to both locations with sudo
sudo install -m 0644 "$SRC" "$HOOK_PATH"
# chown: prefer www-data, fallback nginx if that account exists
if id -u www-data >/dev/null 2>&1; then
  sudo chown www-data:www-data "$HOOK_PATH"
elif id -u nginx >/dev/null 2>&1; then
  sudo chown nginx:nginx "$HOOK_PATH"
fi

# Keep API_PATH in sync if present
if [[ -d "$(dirname "$API_PATH")" ]]; then
  sudo cp -a "$HOOK_PATH" "$API_PATH" || true
fi

echo "[INFO] Head of deployed file (for sanity):"
head -n 60 "$HOOK_PATH" || true

# If opcache is configured to not auto-validate, reload php-fpm if present; otherwise harmless
for SVC in php8.2-fpm php8.1-fpm php8.0-fpm php7.4-fpm php-fpm; do
  if sudo systemctl is-active --quiet "$SVC"; then
    sudo systemctl reload "$SVC" && echo "[INFO] Reloaded $SVC"
  fi
done
# Light nginx reload is safe too (if running)
if sudo systemctl is-active --quiet nginx; then
  sudo systemctl reload nginx && echo "[INFO] Reloaded nginx"
fi

# Quick marker check to confirm the GET handler change was deployed
if grep -Eq "webhook-test|['\"]mode['\"]\s*=>|\"mode\"\s*:\s*\"webhook-test\"" "$HOOK_PATH"; then
  echo "[PASS] Deployed file contains webhook-test/mode marker"
else
  echo "[FAIL] Marker not found after deploy—please re-check the local file you uploaded."
  exit 1
fi
REMOTE

echo "[INFO] Probing webhook GET endpoint"
HTTP="$($CURL -o /tmp/pixel_body -w "%{http_code}" "$WEBHOOK_URL" || true)"
BODY="$(cat /tmp/pixel_body 2>/dev/null || true)"
echo "HTTP: $HTTP"
echo "Body: $BODY"
if [[ "$HTTP" == "200" ]] && echo "$BODY" | grep -qi '"status"\s*:\s*"success"'; then
  echo "[PASS] Webhook GET returns 200 + success JSON. The previous blocker is cleared."
else
  echo "[FAIL] Webhook GET did not return expected 200/success. Check nginx/PHP logs."
  echo "       sudo tail -n 200 /var/log/nginx/access.log | grep pixel_import.php"
  echo "       sudo tail -n 200 /var/log/nginx/error.log"
  exit 2
fi

echo "[INFO] Checking API health (local first, then public)"
if ! $CURL "http://localhost:4000/health" >/tmp/api_health 2>/dev/null; then
  $CURL "https://api.thynkdata.com/health" >/tmp/api_health || true
fi
echo "API Health: $(cat /tmp/api_health || true)"
if grep -qi '"status"\s*:\s*"ok"' /tmp/api_health 2>/dev/null; then
  echo "[PASS] API health OK"
else
  echo "[WARN] API health endpoint not OK from here. This may be separate from the webhook fix."
  echo "       On the VM: pm2 list; pm2 logs --lines 200   OR   sudo journalctl -u autopixel-api -n 200 --no-pager"
fi

echo "[DONE] Deploy + verify complete."