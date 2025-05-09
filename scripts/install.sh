#!/bin/bash
## Do not modify this file. You will lose the ability to install and auto-update!

## Environment variables that can be set:
## ROOT_USERNAME - Predefined root username
## ROOT_USER_EMAIL - Predefined root user email
## ROOT_USER_PASSWORD - Predefined root user password
## DOCKER_ADDRESS_POOL_BASE - Custom Docker address pool base (default: 10.0.0.0/8)
## DOCKER_ADDRESS_POOL_SIZE - Custom Docker address pool size (default: 24)
## DOCKER_POOL_FORCE_OVERRIDE - Force override Docker address pool configuration (default: false)
## AUTOUPDATE - Set to "false" to disable auto-updates
## REGISTRY_URL - Custom registry URL for Docker images (default: ghcr.io)

set -e # Exit immediately if a command exits with a non-zero status
## $1 could be empty, so we need to disable this check
#set -u # Treat unset variables as an error and exit
set -o pipefail # Cause a pipeline to return the status of the last command that exited with a non-zero status
CDN="https://cdn.coollabs.io/coolify"
DATE=$(date +"%Y%m%d-%H%M%S")

OS_TYPE=$(grep -w "ID" /etc/os-release | cut -d "=" -f 2 | tr -d '"')
ENV_FILE="/data/coolify/source/.env"
VERSION="20"
DOCKER_VERSION="27.0"
# TODO: Ask for a user
CURRENT_USER=$USER

if [ $EUID != 0 ]; then
    echo "Please run this script as root or with sudo"
    exit
fi

echo -e "Welcome to Coolify Installer!"
echo -e "This script will install everything for you. Sit back and relax."
echo -e "Source code: https://github.com/coollabsio/coolify/blob/main/scripts/install.sh\n"

# Predefined root user
ROOT_USERNAME=${ROOT_USERNAME:-}
ROOT_USER_EMAIL=${ROOT_USER_EMAIL:-}
ROOT_USER_PASSWORD=${ROOT_USER_PASSWORD:-}

if [ -n "${REGISTRY_URL+x}" ]; then
    echo "Using registry URL from environment variable: $REGISTRY_URL"
else
    if [ -f "$ENV_FILE" ] && grep -q "^REGISTRY_URL=" "$ENV_FILE"; then
        REGISTRY_URL=$(grep "^REGISTRY_URL=" "$ENV_FILE" | cut -d '=' -f2)
        echo "Using registry URL from .env: $REGISTRY_URL"
    else
        REGISTRY_URL="ghcr.io"
        echo "Using default registry URL: $REGISTRY_URL"
    fi
fi

# Docker address pool configuration defaults
DOCKER_ADDRESS_POOL_BASE_DEFAULT="10.0.0.0/8"
DOCKER_ADDRESS_POOL_SIZE_DEFAULT=24

# Check if environment variables were explicitly provided
DOCKER_POOL_BASE_PROVIDED=false
DOCKER_POOL_SIZE_PROVIDED=false
DOCKER_POOL_FORCE_OVERRIDE=${DOCKER_POOL_FORCE_OVERRIDE:-false}

if [ -n "${DOCKER_ADDRESS_POOL_BASE+x}" ]; then
    DOCKER_POOL_BASE_PROVIDED=true
fi

if [ -n "${DOCKER_ADDRESS_POOL_SIZE+x}" ]; then
    DOCKER_POOL_SIZE_PROVIDED=true
fi

restart_docker_service() {
    # Check if systemctl is available
    if command -v systemctl >/dev/null 2>&1; then
        systemctl restart docker
        if [ $? -eq 0 ]; then
            echo " - Docker daemon restarted successfully"
        else
            echo " - Failed to restart Docker daemon"
            return 1
        fi
    # Check if service command is available
    elif command -v service >/dev/null 2>&1; then
        service docker restart
        if [ $? -eq 0 ]; then
            echo " - Docker daemon restarted successfully"
        else
            echo " - Failed to restart Docker daemon"
            return 1
        fi
    # If neither systemctl nor service is available
    else
        echo " - Error: No service management system found"
        return 1
    fi
}

# Function to compare address pools
compare_address_pools() {
    local base1="$1"
    local size1="$2"
    local base2="$3"
    local size2="$4"

    # Normalize CIDR notation for comparison
    local ip1=$(echo "$base1" | cut -d'/' -f1)
    local prefix1=$(echo "$base1" | cut -d'/' -f2)
    local ip2=$(echo "$base2" | cut -d'/' -f1)
    local prefix2=$(echo "$base2" | cut -d'/' -f2)

    # Compare IPs and prefixes
    if [ "$ip1" = "$ip2" ] && [ "$prefix1" = "$prefix2" ] && [ "$size1" = "$size2" ]; then
        return 0 # Pools are the same
    else
        return 1 # Pools are different
    fi
}

# Docker address pool configuration
DOCKER_ADDRESS_POOL_BASE=${DOCKER_ADDRESS_POOL_BASE:-"$DOCKER_ADDRESS_POOL_BASE_DEFAULT"}
DOCKER_ADDRESS_POOL_SIZE=${DOCKER_ADDRESS_POOL_SIZE:-$DOCKER_ADDRESS_POOL_SIZE_DEFAULT}

# Load Docker address pool configuration from .env file if it exists and environment variables were not provided
if [ -f "/data/coolify/source/.env" ] && [ "$DOCKER_POOL_BASE_PROVIDED" = false ] && [ "$DOCKER_POOL_SIZE_PROVIDED" = false ]; then
    ENV_DOCKER_ADDRESS_POOL_BASE=$(grep -E "^DOCKER_ADDRESS_POOL_BASE=" /data/coolify/source/.env | cut -d '=' -f2 || true)
    ENV_DOCKER_ADDRESS_POOL_SIZE=$(grep -E "^DOCKER_ADDRESS_POOL_SIZE=" /data/coolify/source/.env | cut -d '=' -f2 || true)

    if [ -n "$ENV_DOCKER_ADDRESS_POOL_BASE" ]; then
        DOCKER_ADDRESS_POOL_BASE="$ENV_DOCKER_ADDRESS_POOL_BASE"
    fi

    if [ -n "$ENV_DOCKER_ADDRESS_POOL_SIZE" ]; then
        DOCKER_ADDRESS_POOL_SIZE="$ENV_DOCKER_ADDRESS_POOL_SIZE"
    fi
fi

# Check if daemon.json exists and extract existing address pool configuration
EXISTING_POOL_CONFIGURED=false
if [ -f /etc/docker/daemon.json ]; then
    if jq -e '.["default-address-pools"]' /etc/docker/daemon.json >/dev/null 2>&1; then
        EXISTING_POOL_BASE=$(jq -r '.["default-address-pools"][0].base' /etc/docker/daemon.json 2>/dev/null || true)
        EXISTING_POOL_SIZE=$(jq -r '.["default-address-pools"][0].size' /etc/docker/daemon.json 2>/dev/null || true)

        if [ -n "$EXISTING_POOL_BASE" ] && [ -n "$EXISTING_POOL_SIZE" ] && [ "$EXISTING_POOL_BASE" != "null" ] && [ "$EXISTING_POOL_SIZE" != "null" ]; then
            echo "Found existing Docker network pool: $EXISTING_POOL_BASE/$EXISTING_POOL_SIZE"
            EXISTING_POOL_CONFIGURED=true

            # Check if environment variables were explicitly provided
            if [ "$DOCKER_POOL_BASE_PROVIDED" = false ] && [ "$DOCKER_POOL_SIZE_PROVIDED" = false ]; then
                DOCKER_ADDRESS_POOL_BASE="$EXISTING_POOL_BASE"
                DOCKER_ADDRESS_POOL_SIZE="$EXISTING_POOL_SIZE"
            else
                # Check if force override is enabled
                if [ "$DOCKER_POOL_FORCE_OVERRIDE" = true ]; then
                    echo "Force override enabled - network pool will be updated with $DOCKER_ADDRESS_POOL_BASE/$DOCKER_ADDRESS_POOL_SIZE."
                else
                    echo "Custom pool provided but force override not enabled - using existing configuration."
                    echo "To force override, set DOCKER_POOL_FORCE_OVERRIDE=true"
                    echo "This won't change the existing docker networks, only the pool configuration for the newly created networks."
                    DOCKER_ADDRESS_POOL_BASE="$EXISTING_POOL_BASE"
                    DOCKER_ADDRESS_POOL_SIZE="$EXISTING_POOL_SIZE"
                    DOCKER_POOL_BASE_PROVIDED=false
                    DOCKER_POOL_SIZE_PROVIDED=false
                fi
            fi
        fi
    fi
fi

# Validate Docker address pool configuration
if ! [[ $DOCKER_ADDRESS_POOL_BASE =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/[0-9]+$ ]]; then
    echo "Warning: Invalid network pool base format: $DOCKER_ADDRESS_POOL_BASE"
    if [ "$EXISTING_POOL_CONFIGURED" = true ]; then
        echo "Using existing configuration: $EXISTING_POOL_BASE"
        DOCKER_ADDRESS_POOL_BASE="$EXISTING_POOL_BASE"
    else
        echo "Using default configuration: $DOCKER_ADDRESS_POOL_BASE_DEFAULT"
        DOCKER_ADDRESS_POOL_BASE="$DOCKER_ADDRESS_POOL_BASE_DEFAULT"
    fi
fi

if ! [[ $DOCKER_ADDRESS_POOL_SIZE =~ ^[0-9]+$ ]] || [ "$DOCKER_ADDRESS_POOL_SIZE" -lt 16 ] || [ "$DOCKER_ADDRESS_POOL_SIZE" -gt 28 ]; then
    echo "Warning: Invalid network pool size: $DOCKER_ADDRESS_POOL_SIZE (must be 16-28)"
    if [ "$EXISTING_POOL_CONFIGURED" = true ]; then
        echo "Using existing configuration: $EXISTING_POOL_SIZE"
        DOCKER_ADDRESS_POOL_SIZE="$EXISTING_POOL_SIZE"
    else
        echo "Using default configuration: $DOCKER_ADDRESS_POOL_SIZE_DEFAULT"
        DOCKER_ADDRESS_POOL_SIZE=$DOCKER_ADDRESS_POOL_SIZE_DEFAULT
    fi
fi

TOTAL_SPACE=$(df -BG / | awk 'NR==2 {print $2}' | sed 's/G//')
AVAILABLE_SPACE=$(df -BG / | awk 'NR==2 {print $4}' | sed 's/G//')
REQUIRED_TOTAL_SPACE=30
REQUIRED_AVAILABLE_SPACE=20
WARNING_SPACE=false

if [ "$TOTAL_SPACE" -lt "$REQUIRED_TOTAL_SPACE" ]; then
    WARNING_SPACE=true
    cat <<EOF
WARNING: Insufficient total disk space!

Total disk space:     ${TOTAL_SPACE}GB
Required disk space:  ${REQUIRED_TOTAL_SPACE}GB

==================
EOF
fi

if [ "$AVAILABLE_SPACE" -lt "$REQUIRED_AVAILABLE_SPACE" ]; then
    cat <<EOF
WARNING: Insufficient available disk space!

Available disk space:   ${AVAILABLE_SPACE}GB
Required available space: ${REQUIRED_AVAILABLE_SPACE}GB

==================
EOF
    WARNING_SPACE=true
fi

if [ "$WARNING_SPACE" = true ]; then
    echo "Sleeping for 5 seconds."
    sleep 5
fi

mkdir -p /data/coolify/{source,ssh,applications,databases,backups,services,proxy,webhooks-during-maintenance,sentinel}
mkdir -p /data/coolify/ssh/{keys,mux}
mkdir -p /data/coolify/proxy/dynamic

chown -R 9999:root /data/coolify
chmod -R 700 /data/coolify

INSTALLATION_LOG_WITH_DATE="/data/coolify/source/installation-${DATE}.log"

exec > >(tee -a $INSTALLATION_LOG_WITH_DATE) 2>&1

getAJoke() {
    JOKES=$(curl -s --max-time 2 "https://v2.jokeapi.dev/joke/Programming?blacklistFlags=nsfw,religious,political,racist,sexist,explicit&format=txt&type=single" || true)
    if [ "$JOKES" != "" ]; then
        echo -e " - Until then, here's a joke for you:\n"
        echo -e "$JOKES\n"
    fi
}

# Check if the OS is manjaro, if so, change it to arch
if [ "$OS_TYPE" = "manjaro" ] || [ "$OS_TYPE" = "manjaro-arm" ]; then
    OS_TYPE="arch"
fi

# Check if the OS is Endeavour OS, if so, change it to arch
if [ "$OS_TYPE" = "endeavouros" ]; then
    OS_TYPE="arch"
fi

# Check if the OS is Asahi Linux, if so, change it to fedora
if [ "$OS_TYPE" = "fedora-asahi-remix" ]; then
    OS_TYPE="fedora"
fi

# Check if the OS is popOS, if so, change it to ubuntu
if [ "$OS_TYPE" = "pop" ]; then
    OS_TYPE="ubuntu"
fi

# Check if the OS is linuxmint, if so, change it to ubuntu
if [ "$OS_TYPE" = "linuxmint" ]; then
    OS_TYPE="ubuntu"
fi

#Check if the OS is zorin, if so, change it to ubuntu
if [ "$OS_TYPE" = "zorin" ]; then
    OS_TYPE="ubuntu"
fi

if [ "$OS_TYPE" = "arch" ] || [ "$OS_TYPE" = "archarm" ]; then
    OS_VERSION="rolling"
else
    OS_VERSION=$(grep -w "VERSION_ID" /etc/os-release | cut -d "=" -f 2 | tr -d '"')
fi

# Install xargs on Amazon Linux 2023 - lol
if [ "$OS_TYPE" = 'amzn' ]; then
    dnf install -y findutils >/dev/null
fi

LATEST_VERSION=$(curl --silent $CDN/versions.json | grep -i version | xargs | awk '{print $2}' | tr -d ',')
LATEST_HELPER_VERSION=$(curl --silent $CDN/versions.json | grep -i version | xargs | awk '{print $6}' | tr -d ',')
LATEST_REALTIME_VERSION=$(curl --silent $CDN/versions.json | grep -i version | xargs | awk '{print $8}' | tr -d ',')

if [ -z "$LATEST_HELPER_VERSION" ]; then
    LATEST_HELPER_VERSION=latest
fi

if [ -z "$LATEST_REALTIME_VERSION" ]; then
    LATEST_REALTIME_VERSION=latest
fi

case "$OS_TYPE" in
arch | ubuntu | debian | raspbian | centos | fedora | rhel | ol | rocky | sles | opensuse-leap | opensuse-tumbleweed | almalinux | amzn | alpine) ;;
*)
    echo "This script only supports Debian, Redhat, Arch Linux, Alpine Linux, or SLES based operating systems for now."
    exit
    ;;
esac

# Overwrite LATEST_VERSION if user pass a version number
if [ "$1" != "" ]; then
    LATEST_VERSION=$1
    LATEST_VERSION="${LATEST_VERSION,,}"
    LATEST_VERSION="${LATEST_VERSION#v}"
fi

echo -e "---------------------------------------------"
echo "| Operating System  | $OS_TYPE $OS_VERSION"
echo "| Docker            | $DOCKER_VERSION"
echo "| Coolify           | $LATEST_VERSION"
echo "| Helper            | $LATEST_HELPER_VERSION"
echo "| Realtime          | $LATEST_REALTIME_VERSION"
echo "| Docker Pool       | $DOCKER_ADDRESS_POOL_BASE (size $DOCKER_ADDRESS_POOL_SIZE)"
echo "| Registry URL      | $REGISTRY_URL"
echo -e "---------------------------------------------\n"
echo -e "1. Installing required packages (curl, wget, git, jq, openssl). "

case "$OS_TYPE" in
arch)
    pacman -Sy --noconfirm --needed curl wget git jq openssl >/dev/null || true
    ;;
alpine)
    sed -i '/^#.*\/community/s/^#//' /etc/apk/repositories
    apk update >/dev/null
    apk add curl wget git jq openssl >/dev/null
    ;;
ubuntu | debian | raspbian)
    apt-get update -y >/dev/null
    apt-get install -y curl wget git jq openssl >/dev/null
    ;;
centos | fedora | rhel | ol | rocky | almalinux | amzn)
    if [ "$OS_TYPE" = "amzn" ]; then
        dnf install -y wget git jq openssl >/dev/null
    else
        if ! command -v dnf >/dev/null; then
            yum install -y dnf >/dev/null
        fi
        if ! command -v curl >/dev/null; then
            dnf install -y curl >/dev/null
        fi
        dnf install -y wget git jq openssl >/dev/null
    fi
    ;;
sles | opensuse-leap | opensuse-tumbleweed)
    zypper refresh >/dev/null
    zypper install -y curl wget git jq openssl >/dev/null
    ;;
*)
    echo "This script only supports Debian, Redhat, Arch Linux, or SLES based operating systems for now."
    exit
    ;;
esac

echo -e "2. Check OpenSSH server configuration. "

# Detect OpenSSH server
SSH_DETECTED=false
if [ -x "$(command -v systemctl)" ]; then
    if systemctl status sshd >/dev/null 2>&1; then
        echo " - OpenSSH server is installed."
        SSH_DETECTED=true
    elif systemctl status ssh >/dev/null 2>&1; then
        echo " - OpenSSH server is installed."
        SSH_DETECTED=true
    fi
elif [ -x "$(command -v service)" ]; then
    if service sshd status >/dev/null 2>&1; then
        echo " - OpenSSH server is installed."
        SSH_DETECTED=true
    elif service ssh status >/dev/null 2>&1; then
        echo " - OpenSSH server is installed."
        SSH_DETECTED=true
    fi
fi

if [ "$SSH_DETECTED" = "false" ]; then
    echo " - OpenSSH server not detected. Installing OpenSSH server."
    case "$OS_TYPE" in
    arch)
        pacman -Sy --noconfirm openssh >/dev/null
        systemctl enable sshd >/dev/null 2>&1
        systemctl start sshd >/dev/null 2>&1
        ;;
    alpine)
        apk add openssh >/dev/null
        rc-update add sshd default >/dev/null 2>&1
        service sshd start >/dev/null 2>&1
        ;;
    ubuntu | debian | raspbian)
        apt-get update -y >/dev/null
        apt-get install -y openssh-server >/dev/null
        systemctl enable ssh >/dev/null 2>&1
        systemctl start ssh >/dev/null 2>&1
        ;;
    centos | fedora | rhel | ol | rocky | almalinux | amzn)
        if [ "$OS_TYPE" = "amzn" ]; then
            dnf install -y openssh-server >/dev/null
        else
            dnf install -y openssh-server >/dev/null
        fi
        systemctl enable sshd >/dev/null 2>&1
        systemctl start sshd >/dev/null 2>&1
        ;;
    sles | opensuse-leap | opensuse-tumbleweed)
        zypper install -y openssh >/dev/null
        systemctl enable sshd >/dev/null 2>&1
        systemctl start sshd >/dev/null 2>&1
        ;;
    *)
        echo "###############################################################################"
        echo "WARNING: Could not detect and install OpenSSH server - this does not mean that it is not installed or not running, just that we could not detect it."
        echo -e "Please make sure it is installed and running, otherwise Coolify cannot connect to the host system. \n"
        echo "###############################################################################"
        exit 1
        ;;
    esac
    echo " - OpenSSH server installed successfully."
    SSH_DETECTED=true
fi

# Detect SSH PermitRootLogin
SSH_PERMIT_ROOT_LOGIN=$(sshd -T | grep -i "permitrootlogin" | awk '{print $2}') || true
if [ "$SSH_PERMIT_ROOT_LOGIN" = "yes" ] || [ "$SSH_PERMIT_ROOT_LOGIN" = "without-password" ] || [ "$SSH_PERMIT_ROOT_LOGIN" = "prohibit-password" ]; then
    echo " - SSH PermitRootLogin is enabled."
else
    echo " - SSH PermitRootLogin is disabled."
    echo "   If you have problems with SSH, please read this: https://coolify.io/docs/knowledge-base/server/openssh"
fi

# Detect if docker is installed via snap
if [ -x "$(command -v snap)" ]; then
    SNAP_DOCKER_INSTALLED=$(snap list docker >/dev/null 2>&1 && echo "true" || echo "false")
    if [ "$SNAP_DOCKER_INSTALLED" = "true" ]; then
        echo " - Docker is installed via snap."
        echo "   Please note that Coolify does not support Docker installed via snap."
        echo "   Please remove Docker with snap (snap remove docker) and reexecute this script."
        exit 1
    fi
fi

install_docker() {
    curl -s https://releases.rancher.com/install-docker/${DOCKER_VERSION}.sh | sh 2>&1 || true
    if ! [ -x "$(command -v docker)" ]; then
        curl -s https://get.docker.com | sh -s -- --version ${DOCKER_VERSION} 2>&1
        if ! [ -x "$(command -v docker)" ]; then
            echo " - Docker installation failed."
            echo "   Maybe your OS is not supported?"
            echo " - Please visit https://docs.docker.com/engine/install/ and install Docker manually to continue."
            exit 1
        fi
    fi
}
echo -e "3. Check Docker Installation. "
if ! [ -x "$(command -v docker)" ]; then
    echo " - Docker is not installed. Installing Docker. It may take a while."
    getAJoke
    case "$OS_TYPE" in
    "almalinux")
        dnf config-manager --add-repo=https://download.docker.com/linux/centos/docker-ce.repo >/dev/null 2>&1
        dnf install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin >/dev/null 2>&1
        if ! [ -x "$(command -v docker)" ]; then
            echo " - Docker could not be installed automatically. Please visit https://docs.docker.com/engine/install/ and install Docker manually to continue."
            exit 1
        fi
        systemctl start docker >/dev/null 2>&1
        systemctl enable docker >/dev/null 2>&1
        ;;
    "alpine")
        apk add docker docker-cli-compose >/dev/null 2>&1
        rc-update add docker default >/dev/null 2>&1
        service docker start >/dev/null 2>&1
        if ! [ -x "$(command -v docker)" ]; then
            echo " - Failed to install Docker with apk. Try to install it manually."
            echo "   Please visit https://wiki.alpinelinux.org/wiki/Docker for more information."
            exit 1
        fi
        ;;
    "arch")
        pacman -Sy docker docker-compose --noconfirm >/dev/null 2>&1
        systemctl enable docker.service >/dev/null 2>&1
        if ! [ -x "$(command -v docker)" ]; then
            echo " - Failed to install Docker with pacman. Try to install it manually."
            echo "   Please visit https://wiki.archlinux.org/title/docker for more information."
            exit 1
        fi
        ;;
    "amzn")
        dnf install docker -y >/dev/null 2>&1
        DOCKER_CONFIG=${DOCKER_CONFIG:-/usr/local/lib/docker}
        mkdir -p $DOCKER_CONFIG/cli-plugins >/dev/null 2>&1
        curl -sL "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o $DOCKER_CONFIG/cli-plugins/docker-compose >/dev/null 2>&1
        chmod +x $DOCKER_CONFIG/cli-plugins/docker-compose >/dev/null 2>&1
        systemctl start docker >/dev/null 2>&1
        systemctl enable docker >/dev/null 2>&1
        if ! [ -x "$(command -v docker)" ]; then
            echo " - Failed to install Docker with dnf. Try to install it manually."
            echo "   Please visit https://www.cyberciti.biz/faq/how-to-install-docker-on-amazon-linux-2/ for more information."
            exit 1
        fi
        ;;
    "centos" | "fedora" | "rhel")
        if [ -x "$(command -v dnf5)" ]; then
            # dnf5 is available
            dnf config-manager addrepo --from-repofile=https://download.docker.com/linux/$OS_TYPE/docker-ce.repo --overwrite >/dev/null 2>&1
        else
            # dnf5 is not available, use dnf
            dnf config-manager --add-repo=https://download.docker.com/linux/$OS_TYPE/docker-ce.repo >/dev/null 2>&1
        fi
        dnf install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin >/dev/null 2>&1
        if ! [ -x "$(command -v docker)" ]; then
            echo " - Docker could not be installed automatically. Please visit https://docs.docker.com/engine/install/ and install Docker manually to continue."
            exit 1
        fi
        systemctl start docker >/dev/null 2>&1
        systemctl enable docker >/dev/null 2>&1
        ;;
    "ubuntu" | "debian" | "raspbian")
        if [ "$OS_TYPE" = "ubuntu" ] && [ "$OS_VERSION" = "24.10" ]; then
            echo " - Installing Docker for Ubuntu 24.10..."
            apt-get update >/dev/null
            apt-get install -y ca-certificates curl >/dev/null
            install -m 0755 -d /etc/apt/keyrings
            curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
            chmod a+r /etc/apt/keyrings/docker.asc

            # Add the repository to Apt sources
            echo \
                "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
                  $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}") stable" |
                tee /etc/apt/sources.list.d/docker.list >/dev/null
            apt-get update >/dev/null
            apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin >/dev/null

            if ! [ -x "$(command -v docker)" ]; then
                echo " - Docker installation failed."
                echo "   Please visit https://docs.docker.com/engine/install/ubuntu/ and install Docker manually to continue."
                exit 1
            fi
            echo " - Docker installed successfully for Ubuntu 24.10."
        else
            install_docker
        fi
        ;;
    *)
        install_docker
        ;;
    esac
    echo " - Docker installed successfully."
else
    echo " - Docker is installed."
fi

echo -e "4. Check Docker Configuration. "

echo " - Network pool configuration: ${DOCKER_ADDRESS_POOL_BASE}/${DOCKER_ADDRESS_POOL_SIZE}"
echo " - To override existing configuration: DOCKER_POOL_FORCE_OVERRIDE=true"

mkdir -p /etc/docker

# Backup original daemon.json if it exists
if [ -f /etc/docker/daemon.json ]; then
    cp /etc/docker/daemon.json /etc/docker/daemon.json.original-"$DATE"
fi

# Create coolify configuration with or without address pools based on whether they were explicitly provided
if [ "$DOCKER_POOL_FORCE_OVERRIDE" = true ] || [ "$EXISTING_POOL_CONFIGURED" = false ]; then
    # First check if the configuration would actually change anything
    if [ -f /etc/docker/daemon.json ]; then
        CURRENT_POOL_BASE=$(jq -r '.["default-address-pools"][0].base' /etc/docker/daemon.json 2>/dev/null)
        CURRENT_POOL_SIZE=$(jq -r '.["default-address-pools"][0].size' /etc/docker/daemon.json 2>/dev/null)

        if [ "$CURRENT_POOL_BASE" = "$DOCKER_ADDRESS_POOL_BASE" ] && [ "$CURRENT_POOL_SIZE" = "$DOCKER_ADDRESS_POOL_SIZE" ]; then
            echo " - Network pool configuration unchanged, skipping update"
            NEED_MERGE=false
        else
            # If force override is enabled or no existing configuration exists,
            # create a new configuration with the specified address pools
            echo " - Creating new Docker configuration with network pool: ${DOCKER_ADDRESS_POOL_BASE}/${DOCKER_ADDRESS_POOL_SIZE}"
            cat >/etc/docker/daemon.json <<EOL
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  },
  "default-address-pools": [
    {"base":"${DOCKER_ADDRESS_POOL_BASE}","size":${DOCKER_ADDRESS_POOL_SIZE}}
  ]
}
EOL
            NEED_MERGE=true
        fi
    else
        # No existing configuration, create new one
        echo " - Creating new Docker configuration with network pool: ${DOCKER_ADDRESS_POOL_BASE}/${DOCKER_ADDRESS_POOL_SIZE}"
        cat >/etc/docker/daemon.json <<EOL
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  },
  "default-address-pools": [
    {"base":"${DOCKER_ADDRESS_POOL_BASE}","size":${DOCKER_ADDRESS_POOL_SIZE}}
  ]
}
EOL
        NEED_MERGE=true
    fi
else
    # Check if we need to update log settings
    if [ -f /etc/docker/daemon.json ] && jq -e '.["log-driver"] == "json-file" and .["log-opts"]["max-size"] == "10m" and .["log-opts"]["max-file"] == "3"' /etc/docker/daemon.json >/dev/null 2>&1; then
        echo " - Log configuration is up to date"
        NEED_MERGE=false
    else
        # Create a configuration without address pools to preserve existing ones
        cat >/etc/docker/daemon.json.coolify <<EOL
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  }
}
EOL
        NEED_MERGE=true
    fi
fi

# Remove the duplicate daemon.json creation since we handle it above
if ! [ -f /etc/docker/daemon.json ]; then
    # If no daemon.json exists, create it with default settings
    cat >/etc/docker/daemon.json <<EOL
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  },
  "default-address-pools": [
    {"base":"${DOCKER_ADDRESS_POOL_BASE}","size":${DOCKER_ADDRESS_POOL_SIZE}}
  ]
}
EOL
    NEED_MERGE=false
fi

if [ -s /etc/docker/daemon.json.original-"$DATE" ]; then
    DIFF=$(diff <(jq --sort-keys . /etc/docker/daemon.json) <(jq --sort-keys . /etc/docker/daemon.json.original-"$DATE") || true)
    if [ "$DIFF" != "" ]; then
        echo " - Checking configuration changes..."

        # Check if address pools were changed
        if echo "$DIFF" | grep -q "default-address-pools"; then
            if [ "$DOCKER_POOL_BASE_PROVIDED" = true ] || [ "$DOCKER_POOL_SIZE_PROVIDED" = true ]; then
                echo " - Network pool updated per user request"
            else
                echo " - Warning: Network pool modified without explicit request"
            fi
        fi

        # Remove this redundant restart since we already restarted when writing the config
        echo " - Configuration changes confirmed"
        if [ "$NEED_MERGE" = true ]; then
            echo " - Configuration updated - restarting Docker daemon..."
            restart_docker_service
        else
            echo " - Configuration is up to date"
        fi
    else
        echo " - Configuration is up to date"
    fi
else
    if [ "$NEED_MERGE" = true ]; then
        echo " - Configuration updated - restarting Docker daemon..."
        restart_docker_service
    else
        echo " - Configuration is up to date"
    fi
fi

echo -e "5. Download required files from CDN. "
curl -fsSL $CDN/docker-compose.yml -o /data/coolify/source/docker-compose.yml
curl -fsSL $CDN/docker-compose.prod.yml -o /data/coolify/source/docker-compose.prod.yml
curl -fsSL $CDN/.env.production -o /data/coolify/source/.env.production
curl -fsSL $CDN/upgrade.sh -o /data/coolify/source/upgrade.sh

echo -e "6. Make backup of .env to .env-$DATE"

# Copy .env.example if .env does not exist
if [ -f $ENV_FILE ]; then
    cp $ENV_FILE $ENV_FILE-$DATE
else
    echo " - File does not exist: $ENV_FILE"
    echo " - Copying .env.production to .env-$DATE"
    cp /data/coolify/source/.env.production $ENV_FILE-$DATE
    # Generate a secure APP_ID and APP_KEY
    sed -i "s|^APP_ID=.*|APP_ID=$(openssl rand -hex 16)|" "$ENV_FILE-$DATE"
    sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" "$ENV_FILE-$DATE"

    # Generate a secure Postgres DB username and password
    # Causes issues: database "random-user" does not exist
    # sed -i "s|^DB_USERNAME=.*|DB_USERNAME=$(openssl rand -hex 16)|" "$ENV_FILE-$DATE"
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -base64 32)|" "$ENV_FILE-$DATE"

    # Generate a secure Redis password
    sed -i "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=$(openssl rand -base64 32)|" "$ENV_FILE-$DATE"

    # Generate secure Pusher credentials
    sed -i "s|^PUSHER_APP_ID=.*|PUSHER_APP_ID=$(openssl rand -hex 32)|" "$ENV_FILE-$DATE"
    sed -i "s|^PUSHER_APP_KEY=.*|PUSHER_APP_KEY=$(openssl rand -hex 32)|" "$ENV_FILE-$DATE"
    sed -i "s|^PUSHER_APP_SECRET=.*|PUSHER_APP_SECRET=$(openssl rand -hex 32)|" "$ENV_FILE-$DATE"
fi

# Add default root user credentials from environment variables
if [ -n "$ROOT_USERNAME" ] && [ -n "$ROOT_USER_EMAIL" ] && [ -n "$ROOT_USER_PASSWORD" ]; then
    if grep -q "^ROOT_USERNAME=" "$ENV_FILE-$DATE"; then
        sed -i "s|^ROOT_USERNAME=.*|ROOT_USERNAME=$ROOT_USERNAME|" "$ENV_FILE-$DATE"
    fi
    if grep -q "^ROOT_USER_EMAIL=" "$ENV_FILE-$DATE"; then
        sed -i "s|^ROOT_USER_EMAIL=.*|ROOT_USER_EMAIL=$ROOT_USER_EMAIL|" "$ENV_FILE-$DATE"
    fi
    if grep -q "^ROOT_USER_PASSWORD=" "$ENV_FILE-$DATE"; then
        sed -i "s|^ROOT_USER_PASSWORD=.*|ROOT_USER_PASSWORD=$ROOT_USER_PASSWORD|" "$ENV_FILE-$DATE"
    fi
fi

# Add registry URL to .env file
if [ -n "${REGISTRY_URL+x}" ]; then
    # Only update if REGISTRY_URL was explicitly provided
    if grep -q "^REGISTRY_URL=" "$ENV_FILE-$DATE"; then
        sed -i "s|^REGISTRY_URL=.*|REGISTRY_URL=$REGISTRY_URL|" "$ENV_FILE-$DATE"
    else
        echo "REGISTRY_URL=$REGISTRY_URL" >>"$ENV_FILE-$DATE"
    fi
fi

# Merge .env and .env.production. New values will be added to .env
echo -e "7. Propagating .env with new values - if necessary."
awk -F '=' '!seen[$1]++' "$ENV_FILE-$DATE" /data/coolify/source/.env.production >$ENV_FILE

if [ "$AUTOUPDATE" = "false" ]; then
    if ! grep -q "AUTOUPDATE=" /data/coolify/source/.env; then
        echo "AUTOUPDATE=false" >>/data/coolify/source/.env
    else
        sed -i "s|AUTOUPDATE=.*|AUTOUPDATE=false|g" /data/coolify/source/.env
    fi
fi

# Save Docker address pool configuration to .env file
if ! grep -q "DOCKER_ADDRESS_POOL_BASE=" /data/coolify/source/.env; then
    echo "DOCKER_ADDRESS_POOL_BASE=$DOCKER_ADDRESS_POOL_BASE" >>/data/coolify/source/.env
else
    # Only update if explicitly provided
    if [ "$DOCKER_POOL_BASE_PROVIDED" = true ]; then
        sed -i "s|DOCKER_ADDRESS_POOL_BASE=.*|DOCKER_ADDRESS_POOL_BASE=$DOCKER_ADDRESS_POOL_BASE|g" /data/coolify/source/.env
    fi
fi

if ! grep -q "DOCKER_ADDRESS_POOL_SIZE=" /data/coolify/source/.env; then
    echo "DOCKER_ADDRESS_POOL_SIZE=$DOCKER_ADDRESS_POOL_SIZE" >>/data/coolify/source/.env
else
    # Only update if explicitly provided
    if [ "$DOCKER_POOL_SIZE_PROVIDED" = true ]; then
        sed -i "s|DOCKER_ADDRESS_POOL_SIZE=.*|DOCKER_ADDRESS_POOL_SIZE=$DOCKER_ADDRESS_POOL_SIZE|g" /data/coolify/source/.env
    fi
fi

echo -e "8. Checking for SSH key for localhost access."
if [ ! -f ~/.ssh/authorized_keys ]; then
    mkdir -p ~/.ssh
    chmod 700 ~/.ssh
    touch ~/.ssh/authorized_keys
    chmod 600 ~/.ssh/authorized_keys
fi

set +e
IS_COOLIFY_VOLUME_EXISTS=$(docker volume ls | grep coolify-db | wc -l)
set -e

if [ "$IS_COOLIFY_VOLUME_EXISTS" -eq 0 ]; then
    echo " - Generating SSH key."
    ssh-keygen -t ed25519 -a 100 -f /data/coolify/ssh/keys/id.$CURRENT_USER@host.docker.internal -q -N "" -C coolify
    chown 9999 /data/coolify/ssh/keys/id.$CURRENT_USER@host.docker.internal
    sed -i "/coolify/d" ~/.ssh/authorized_keys
    cat /data/coolify/ssh/keys/id.$CURRENT_USER@host.docker.internal.pub >>~/.ssh/authorized_keys
    rm -f /data/coolify/ssh/keys/id.$CURRENT_USER@host.docker.internal.pub
fi

chown -R 9999:root /data/coolify
chmod -R 700 /data/coolify

echo -e "9. Installing Coolify ($LATEST_VERSION)"
echo -e " - It could take a while based on your server's performance, network speed, stars, etc."
echo -e " - Please wait."
getAJoke

if [[ $- == *x* ]]; then
    bash -x /data/coolify/source/upgrade.sh "${LATEST_VERSION:-latest}" "${LATEST_HELPER_VERSION:-latest}" "${REGISTRY_URL:-ghcr.io}"
else
    bash /data/coolify/source/upgrade.sh "${LATEST_VERSION:-latest}" "${LATEST_HELPER_VERSION:-latest}" "${REGISTRY_URL:-ghcr.io}"
fi
echo " - Coolify installed successfully."
rm -f $ENV_FILE-$DATE

echo " - Waiting for 20 seconds for Coolify (database migrations) to be ready."
getAJoke

sleep 20
echo -e "\033[0;35m
   ____                            _         _       _   _                 _
  / ___|___  _ __   __ _ _ __ __ _| |_ _   _| | __ _| |_(_) ___  _ __  ___| |
 | |   / _ \| '_ \ / _\` | '__/ _\` | __| | | | |/ _\` | __| |/ _ \| '_ \/ __| |
 | |__| (_) | | | | (_| | | | (_| | |_| |_| | | (_| | |_| | (_) | | | \__ \_|
  \____\___/|_| |_|\__, |_|  \__,_|\__|\__,_|_|\__,_|\__|_|\___/|_| |_|___(_)
                   |___/
\033[0m"

IPV4_PUBLIC_IP=$(curl -4s https://ifconfig.io || true)
IPV6_PUBLIC_IP=$(curl -6s https://ifconfig.io || true)

echo -e "\nYour instance is ready to use!\n"
if [ -n "$IPV4_PUBLIC_IP" ]; then
    echo -e "You can access Coolify through your Public IPV4: http://$(curl -4s https://ifconfig.io):8000"
fi
if [ -n "$IPV6_PUBLIC_IP" ]; then
    echo -e "You can access Coolify through your Public IPv6: http://[$IPV6_PUBLIC_IP]:8000"
fi

set +e
DEFAULT_PRIVATE_IP=$(ip route get 1 | sed -n 's/^.*src \([0-9.]*\) .*$/\1/p')
PRIVATE_IPS=$(hostname -I 2>/dev/null || ip -o addr show scope global | awk '{print $4}' | cut -d/ -f1)
set -e

if [ -n "$PRIVATE_IPS" ]; then
    echo -e "\nIf your Public IP is not accessible, you can use the following Private IPs:\n"
    for IP in $PRIVATE_IPS; do
        if [ "$IP" != "$DEFAULT_PRIVATE_IP" ]; then
            echo -e "http://$IP:8000"
        fi
    done
fi
echo -e "\nWARNING: It is highly recommended to backup your Environment variables file (/data/coolify/source/.env) to a safe location, outside of this server (e.g. into a Password Manager).\n"
cp /data/coolify/source/.env /data/coolify/source/.env.backup
