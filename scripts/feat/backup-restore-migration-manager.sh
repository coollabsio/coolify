#!/bin/bash
# ======================================================================
# Coolify Backup & Restore Manager (Unified & Distro-Agnostic)
# ======================================================================
set -e

# Ensure script is run as root
if [ "$(id -u)" -ne 0 ]; then
    echo "❌ Error: This script must be run as root."
    exit 1
fi

# ==========================================
# CORE FUNCTION: BACKUP
# ==========================================
perform_backup() {
    local DEST_DIR="${1:-/tmp}"
    local PREFIX="${2:-coolify_backup}"
    local TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    local BACKUP_NAME="${PREFIX}_${TIMESTAMP}"
    local BACKUP_DIR="/tmp/$BACKUP_NAME"

    echo "====================================================="
    echo "📦 Starting Coolify Backup: $BACKUP_NAME"
    echo "====================================================="

    mkdir -p "$BACKUP_DIR/ssh/keys"
    mkdir -p "$DEST_DIR"

    # Capture Coolify Version
    if docker ps | grep -q "coolify$"; then
        COOLIFY_VERSION=$(docker inspect -f '{{.Config.Image}}' coolify | awk -F':' '{print $2}')
        echo "$COOLIFY_VERSION" > "$BACKUP_DIR/coolify_version.txt"
        echo "📌 Coolify version detected: $COOLIFY_VERSION"
    else
        echo "unknown" > "$BACKUP_DIR/coolify_version.txt"
        echo "⚠️ Warning: Coolify container not running. Version set to unknown."
    fi

    echo "[1/4] Dumping Coolify database..."
    if ! docker exec coolify-db pg_dump -U coolify -d coolify -F c > "$BACKUP_DIR/database.dump" 2>/dev/null; then
        echo "❌ Error: Database dump failed. Is the coolify-db container running?"
        rm -rf "$BACKUP_DIR"
        exit 1
    fi

    echo "[2/4] Backing up Coolify .env file..."
    [ -f "/data/coolify/source/.env" ] && cp /data/coolify/source/.env "$BACKUP_DIR/.env"

    echo "[3/4] Backing up Coolify SSH keys..."
    [ -d "/data/coolify/ssh/keys" ] && cp -a /data/coolify/ssh/keys/* "$BACKUP_DIR/ssh/keys/" 2>/dev/null || true

    echo "[4/4] Backing up authorized_keys..."
    [ -f /root/.ssh/authorized_keys ] && cp /root/.ssh/authorized_keys "$BACKUP_DIR/authorized_keys"

    echo "Packaging everything into $DEST_DIR/$BACKUP_NAME.tar.gz..."
    cd /tmp
    tar -czf "$DEST_DIR/$BACKUP_NAME.tar.gz" "$BACKUP_NAME"
    rm -rf "$BACKUP_DIR"

    echo "✅ Backup Completed Successfully!"
    echo "💾 Saved at: $DEST_DIR/$BACKUP_NAME.tar.gz"
    echo "====================================================="
}

# ==========================================
# CORE FUNCTION: RESTORE
# ==========================================
perform_restore() {
    local TAR_PATH="$1"
    local MATCH_VERSION="$2"
    local TAKE_BACKUP="$3"

    echo "====================================================="
    echo "🔄 Starting Coolify Auto-Restore"
    echo "====================================================="

    # Interactive prompts if CLI args are missing
    if [ -z "$TAR_PATH" ]; then
        read -p "📁 Enter the absolute path to your Coolify backup .tar.gz file: " TAR_PATH
    fi

    if [ ! -f "$TAR_PATH" ]; then
        echo "❌ Error: File not found at $TAR_PATH"
        exit 1
    fi

    if [ -z "$TAKE_BACKUP" ]; then
        read -p "🛡️ Do you want to take a safety backup of the CURRENT instance before restoring? (y/n): " TAKE_BACKUP
    fi

    # Execute Pre-Restore Safety Backup using the shared function
    if [[ "$TAKE_BACKUP" =~ ^[Yy]$ ]]; then
        echo ""
        echo "⏳ Executing Safety Backup prior to restoration..."
        perform_backup "/tmp" "coolify_safety_backup"
        echo "⏳ Resuming Restoration Process..."
        echo ""
    fi

    RESTORE_DIR="/tmp/coolify_restore_$(date +%Y%m%d_%H%M%S)"
    mkdir -p "$RESTORE_DIR"
    
    echo "Extracting backup archive..."
    tar -xzf "$TAR_PATH" -C "$RESTORE_DIR"
    BACKUP_FOLDER=$(find "$RESTORE_DIR" -mindepth 1 -maxdepth 1 -type d | head -n 1)

    # Handle Version Matching
    BACKUP_VERSION="unknown"
    if [ -f "$BACKUP_FOLDER/coolify_version.txt" ]; then
        BACKUP_VERSION=$(cat "$BACKUP_FOLDER/coolify_version.txt")
    fi

    if [ -z "$MATCH_VERSION" ]; then
        echo "📌 The backup is from Coolify version: $BACKUP_VERSION"
        read -p "🔄 Install this EXACT version? (Press 'n' to use latest version) (y/n): " MATCH_VERSION
    fi

    INSTALL_CMD="curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash"
    if [[ "$MATCH_VERSION" =~ ^[Yy]$ ]] && [ "$BACKUP_VERSION" != "unknown" ]; then
        echo "📌 Pinning installation to version $BACKUP_VERSION..."
        INSTALL_CMD="curl -fsSL https://cdn.coollabs.io/coolify/install.sh | VERSION=$BACKUP_VERSION bash"
    fi

    echo "[1/5] Stopping Coolify containers (keeping database running)..."
    docker stop coolify coolify-redis coolify-realtime coolify-proxy 2>/dev/null || true
    docker start coolify-db 2>/dev/null || true 
    sleep 4

    echo "[2/5] Restoring database..."
    if [ -f "$BACKUP_FOLDER/database.dump" ]; then
        docker exec -i coolify-db pg_restore --verbose --clean --no-acl --no-owner -U coolify -d coolify < "$BACKUP_FOLDER/database.dump"
    else
        echo "❌ Error: database.dump missing from backup!"
        exit 1
    fi

    echo "[3/5] Restoring SSH keys..."
    if [ -d "$BACKUP_FOLDER/ssh/keys" ] && [ "$(ls -A "$BACKUP_FOLDER/ssh/keys" 2>/dev/null)" ]; then
        mkdir -p /data/coolify/ssh/keys
        cp -a "$BACKUP_FOLDER/ssh/keys/"* /data/coolify/ssh/keys/
        chmod 600 /data/coolify/ssh/keys/* 2>/dev/null || true
    fi

    if [ -f "$BACKUP_FOLDER/authorized_keys" ]; then
        mkdir -p /root/.ssh
        chmod 700 /root/.ssh
        touch /root/.ssh/authorized_keys
        chmod 600 /root/.ssh/authorized_keys
        while IFS= read -r key; do
            if [ -n "$key" ] && ! grep -qF "$key" /root/.ssh/authorized_keys; then
                echo "$key" >> /root/.ssh/authorized_keys
            fi
        done < "$BACKUP_FOLDER/authorized_keys"
    fi

    echo "[4/5] Updating APP_PREVIOUS_KEYS in .env..."
    NEW_ENV_FILE="/data/coolify/source/.env"
    if [ -f "$BACKUP_FOLDER/.env" ] && [ -f "$NEW_ENV_FILE" ]; then
        OLD_APP_KEY=$(grep '^APP_KEY=' "$BACKUP_FOLDER/.env" | cut -d '=' -f2- | tr -d '"' | tr -d "'" | tr -d '\r' || true)
        if [ -n "$OLD_APP_KEY" ]; then
            if grep -q "^APP_PREVIOUS_KEYS=" "$NEW_ENV_FILE"; then
                if ! grep -q "$OLD_APP_KEY" "$NEW_ENV_FILE"; then
                    sed -i "s|^APP_PREVIOUS_KEYS=.*|&,$OLD_APP_KEY|" "$NEW_ENV_FILE"
                fi
            else
                echo "APP_PREVIOUS_KEYS=$OLD_APP_KEY" >> "$NEW_ENV_FILE"
            fi
        fi
    fi

    echo "[5/5] Restarting Coolify to apply changes..."
    eval "$INSTALL_CMD"

    # Clean up
    rm -rf "$RESTORE_DIR"

    echo "====================================================="
    echo "✅ Restoration Completed Successfully!"
    echo "Refresh your Coolify dashboard."
    echo "====================================================="
}

# ==========================================
# CLI ARGUMENT PARSER & MENU LOGIC
# ==========================================
ACTION=""
TAR_PATH=""
MATCH_VERSION=""
TAKE_BACKUP=""
DEST_DIR="/tmp"

while [[ "$#" -gt 0 ]]; do
    case $1 in
        backup|restore) ACTION="$1"; shift ;;
        -f|--file) TAR_PATH="$2"; shift 2 ;;
        -v|--version-match) MATCH_VERSION="$2"; shift 2 ;;
        -b|--backup-first) TAKE_BACKUP="$2"; shift 2 ;;
        -d|--dest) DEST_DIR="$2"; shift 2 ;;
        *) echo "Unknown parameter passed: $1"; exit 1 ;;
    esac
done

# If no action was passed via CLI, show interactive menu
if [ -z "$ACTION" ]; then
    clear
    echo "====================================================="
    echo "   🚀 Coolify Management Tool"
    echo "====================================================="
    echo " 1) Backup Current Coolify Instance"
    echo " 2) Restore Coolify from Backup Archive"
    echo "====================================================="
    read -p "Select an option (1 or 2): " MENU_CHOICE

    if [ "$MENU_CHOICE" == "1" ]; then
        ACTION="backup"
    elif [ "$MENU_CHOICE" == "2" ]; then
        ACTION="restore"
    else
        echo "❌ Invalid choice. Exiting."
        exit 1
    fi
fi

# Route to the correct function based on action
if [ "$ACTION" == "backup" ]; then
    perform_backup "$DEST_DIR" "coolify_backup"
elif [ "$ACTION" == "restore" ]; then
    perform_restore "$TAR_PATH" "$MATCH_VERSION" "$TAKE_BACKUP"
fi
