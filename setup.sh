#!/bin/bash

# Configuration
SWAG_CONTAINER="swag"
MONITOR_PATH="/config/www/uptime/pinger.php"
CRON_SCHEDULE="*/5 * * * *"

echo "--- Uptime Monitor Cron Setup ---"

# 1. Check if pinger exists
if docker exec $SWAG_CONTAINER ls $MONITOR_PATH >/dev/null 2>&1; then
    echo "[OK] Found pinger.php inside $SWAG_CONTAINER"
else
    echo "[ERROR] pinger.php not found at $MONITOR_PATH inside the container."
    exit 1
fi

# 2. Add to crontab if not already there
CRON_LINE="$CRON_SCHEDULE php $MONITOR_PATH"
(docker exec $SWAG_CONTAINER crontab -l 2>/dev/null | grep -v "$MONITOR_PATH" ; echo "$CRON_LINE") | docker exec -i $SWAG_CONTAINER crontab -

echo "[OK] Cronjob added: $CRON_SCHEDULE"
echo "To verify, run: docker exec $SWAG_CONTAINER crontab -l"