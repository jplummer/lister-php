#!/bin/bash
# Lister Deployment Script
# Uploads files to web server root directory

# Load environment variables from project root
source "$(dirname "$0")/../.env"

SCRIPT_DIR="$(dirname "$0")"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"

# Get git commit hash for display (optional)
GIT_COMMIT_SHORT=$(cd "$REPO_ROOT" && git rev-parse --short HEAD 2>/dev/null || echo "")

echo "Deploying Lister to web server..."
echo "Commit: $GIT_COMMIT_SHORT"
echo "Host: $HOST_SERVER"
echo "User: $HOST_USERNAME"
echo "Path: $HOST_REMOTE_PATH"

# Write deployment timestamp to file for app to read (last 8 digits of unix timestamp)
DEPLOY_TIMESTAMP=$(date +%s)
DEPLOY_ID=${DEPLOY_TIMESTAMP: -8}
DEPLOY_DATE=$(date -u +"%Y-%m-%d %H:%M:%S UTC")
echo "$DEPLOY_ID" > "$REPO_ROOT/lister/.deploy-timestamp"

# Upload files to root directory
# lister/ is uploaded piece by piece so local lister/data/ (dev logs, per-IP files) never ships
# Retired files are removed with -rm (the leading - ignores "not found")
# Use sshpass if available and password is set, otherwise try SSH key or prompt
if command -v sshpass >/dev/null 2>&1 && [ -n "$HOST_PASSWORD" ]; then
  # Use sshpass to provide password non-interactively
  sshpass -p "$HOST_PASSWORD" sftp -o StrictHostKeyChecking=no -o PreferredAuthentications=password,publickey $HOST_USERNAME@$HOST_SERVER << EOF
cd $HOST_REMOTE_PATH
put index.php
put .htaccess
-mkdir lister
put lister/*.php lister/
put lister/.deploy-timestamp lister/
put -r lister/assets lister/
put -r lister/config lister/
put -r lister/includes lister/
put -r lister/templates lister/
-rm lister/admin.php
quit
EOF
else
  # Try SSH key first, fallback to password prompt if needed
  if [ -n "$HOST_PASSWORD" ] && ! command -v sshpass >/dev/null 2>&1; then
    echo "Note: HOST_PASSWORD is set but sshpass is not installed."
    echo "Install sshpass to use password authentication automatically:"
    echo "  macOS: brew install hudochenkov/sshpass/sshpass"
    echo "  Linux: apt-get install sshpass (or yum install sshpass)"
    echo ""
    echo "Falling back to interactive password prompt or SSH key..."
    echo ""
  fi
  sftp -o StrictHostKeyChecking=no -o PreferredAuthentications=publickey,password $HOST_USERNAME@$HOST_SERVER << EOF
cd $HOST_REMOTE_PATH
put index.php
put .htaccess
-mkdir lister
put lister/*.php lister/
put lister/.deploy-timestamp lister/
put -r lister/assets lister/
put -r lister/config lister/
put -r lister/includes lister/
put -r lister/templates lister/
-rm lister/admin.php
quit
EOF
fi

echo "Deployment complete!"
echo "Deployed at: $DEPLOY_DATE (ID: $DEPLOY_ID)"
echo "Visit: https://$HOST_DOMAIN/"
