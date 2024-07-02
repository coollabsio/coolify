#!/bin/bash
## Do not modify this file. You will lose the ability to install and auto-update!

set -e # Exit immediately if a command exits with a non-zero status
## $1 could be empty, so we need to disable this check
#set -u # Treat unset variables as an error and exit
set -o pipefail # Cause a pipeline to return the status of the last command that exited with a non-zero status

# sudo_check
if [ $EUID != 0 ]; then
    echo "Please run as root"
    exit
fi

# ---------------------
# CONSTANTS
# ---------------------

# shellcheck disable=SC2034
VERSION="1.3.3"
DOCKER_VERSION="26.0"
CDN="https://cdn.coollabs.io/coolify"
OS_TYPE=$(grep -w "ID" /etc/os-release | cut -d "=" -f 2 | tr -d '"')
DATE=$(date +"%Y%m%d-%H%M%S")

# ---------------------
# OPTIONALS
# ---------------------

# ATTENTION: ADVANCED USE ONLY
#
# Set COOLIFY_ROOT_PATH to change the installation directory
#  - useful for immutable OSes like openSUSE MicroOS, Fedora Silverblue
COOLIFY_ROOT_PATH=${COOLIFY_ROOT_PATH:-"/data/coolify"}

# Set SKIP_OS to allow installation on unsupported platforms
# watch out as this skips dependencies installs too
SKIP_OS=${SKIP_OS:-}

#region FUNCTIONS AND HELPERS
# ----------------------
# FUNCTIONS AND HELPERS
# ----------------------

early_checks_and_warnings() {
    check_colors

    if [ ! -w "$COOLIFY_ROOT_PATH" ]; then
        echo "${red}Error: The installation directory for Coolify is not writable. Please check your permissions and try again.${normal}"
        exit 1
    fi

    if [ -n "$SKIP_OS" ]; then
        echo "${yellow}Warning: Allowing installation on unsupported platforms.${normal}"
        echo "${yellow}Warning: Skiping dependencies installation.${normal}"
    fi
}

add_ssh_key() {
    cat "$COOLIFY_ROOT_PATH"/ssh/keys/id.root@host.docker.internal.pub >>~/.ssh/authorized_keys
    chmod 600 ~/.ssh/authorized_keys
}

rewrite_and_check_os_type() {
    if [ -n "$SKIP_OS" ]; then
        return
    fi

    # Check if the OS is manjaro, if so, change it to arch
    if [ "$OS_TYPE" = "manjaro" ] || [ "$OS_TYPE" = "manjaro-arm" ]; then
        OS_TYPE="arch"
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

    case "$OS_TYPE" in
    arch | ubuntu | debian | raspbian | centos | fedora | rhel | ol | rocky | sles | opensuse-leap | opensuse-tumbleweed | almalinux | amzn) ;;
    *)
        echo "${red}This script only supports Debian, Redhat, Arch Linux, or SLES based operating systems for now.${normal}"
        exit
        ;;
    esac
}

welcome_message() {
    echo -e "$(coolify_logo)"
    echo -e "${bold}${magenta}Welcome to Coolify v4 beta installer!${normal}"
    echo -e "This script will install everything for you."
    echo -e "(Source code: https://github.com/coollabsio/coolify/blob/main/scripts/install.sh )\n"
    echo -e "-------------"

    echo "OS: $OS_TYPE $OS_VERSION"
    echo "Coolify version: $LATEST_VERSION"

    echo -e "-------------"
}

install_dependencies() {
    if [ -n "$SKIP_OS" ]; then
        return
    fi

    echo "Installing required packages..."

    case "$OS_TYPE" in
    arch)
        pacman -Sy --noconfirm --needed curl wget git jq >/dev/null || true
        ;;
    ubuntu | debian | raspbian)
        apt update -y >/dev/null
        apt install -y curl wget git jq >/dev/null
        ;;
    centos | fedora | rhel | ol | rocky | almalinux | amzn)
        if [ "$OS_TYPE" = "amzn" ]; then
            dnf install -y wget git jq >/dev/null
        else
            if ! command -v dnf >/dev/null; then
                yum install -y dnf >/dev/null
            fi
            dnf install -y curl wget git jq >/dev/null
        fi
        ;;
    sles | opensuse-leap | opensuse-tumbleweed)
        zypper refresh >/dev/null
        zypper install -y curl wget git jq >/dev/null
        ;;
    *)
        echo "${red}This script only supports Debian, Redhat, Arch Linux, or SLES based operating systems for now.${normal}"
        exit
        ;;
    esac
}

detect_openssh_server() {
    SSH_DETECTED=false
    if [ -x "$(command -v systemctl)" ]; then
        if systemctl status sshd >/dev/null 2>&1; then
            echo "OpenSSH server is installed."
            SSH_DETECTED=true
        fi
        if systemctl status ssh >/dev/null 2>&1; then
            echo "OpenSSH server is installed."
            SSH_DETECTED=true
        fi
    elif [ -x "$(command -v service)" ]; then
        if service sshd status >/dev/null 2>&1; then
            echo "OpenSSH server is installed."
            SSH_DETECTED=true
        fi
        if service ssh status >/dev/null 2>&1; then
            echo "OpenSSH server is installed."
            SSH_DETECTED=true
        fi
    fi
    if [ "$SSH_DETECTED" = "false" ]; then
        echo "${yellow}WARNING: Could not detect if OpenSSH server is installed and running - this does not mean that it is not installed, just that we could not detect it."
        echo -e "Please make sure it is set, otherwise Coolify cannot connect to the host system. ${normal}\n"
    fi
}

detect_ssh_permit_root_login() {
    SSH_PERMIT_ROOT_LOGIN=false
    SSH_PERMIT_ROOT_LOGIN_CONFIG=$(grep "^PermitRootLogin" /etc/ssh/sshd_config | awk '{print $2}') || SSH_PERMIT_ROOT_LOGIN_CONFIG="N/A (commented out or not found at all)"
    if [ "$SSH_PERMIT_ROOT_LOGIN_CONFIG" = "prohibit-password" ] || [ "$SSH_PERMIT_ROOT_LOGIN_CONFIG" = "yes" ] || [ "$SSH_PERMIT_ROOT_LOGIN_CONFIG" = "without-password" ]; then
        echo "PermitRootLogin is enabled."
        SSH_PERMIT_ROOT_LOGIN=true
    fi

    if [ "$SSH_PERMIT_ROOT_LOGIN" != "true" ]; then
        echo "${yellow}WARNING: PermitRootLogin is not enabled in /etc/ssh/sshd_config."
        echo -e "It is set to $SSH_PERMIT_ROOT_LOGIN_CONFIG. Should be prohibit-password, yes or without-password."
        echo -e "We recommend creating a /etc/ssh/sshd_config.d/70-coolify.conf instead of modifying /etc/ssh/sshd_config directly."
        echo -e "Please make sure it is set, otherwise Coolify cannot connect to the host system.${normal}\n"
    fi
}

install_docker() {
    # Detect if docker is installed via snap
    if [ -x "$(command -v snap)" ]; then
        if snap list | grep -q docker; then
            echo "Docker is installed via snap."
            echo "Please note that Coolify does not support Docker installed via snap."
            echo "Please remove Docker with snap (snap remove docker) and reexecute this script."
            exit 1
        fi
    fi

    if ! [ -x "$(command -v docker)" ]; then
        # Almalinux
        if [ "$OS_TYPE" == 'almalinux' ]; then
            dnf config-manager --add-repo=https://download.docker.com/linux/centos/docker-ce.repo
            dnf install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
            if ! [ -x "$(command -v docker)" ]; then
                echo "Docker could not be installed automatically. Please visit https://docs.docker.com/engine/install/ and install Docker manually to continue."
                exit 1
            fi
            systemctl start docker
            systemctl enable docker
        else
            set +e
            if ! [ -x "$(command -v docker)" ]; then
                echo "Docker is not installed. Installing Docker."
                # Arch Linux
                if [ "$OS_TYPE" = "arch" ]; then
                    pacman -Sy docker docker-compose --noconfirm
                    systemctl enable docker.service
                    if [ -x "$(command -v docker)" ]; then
                        echo "Docker installed successfully."
                    else
                        echo "Failed to install Docker with pacman. Try to install it manually."
                        echo "Please visit https://wiki.archlinux.org/title/docker for more information."
                        exit
                    fi
                else
                    # Amazon Linux 2023
                    if [ "$OS_TYPE" = "amzn" ]; then
                        dnf install docker -y
                        DOCKER_CONFIG=${DOCKER_CONFIG:-/usr/local/lib/docker}
                        mkdir -p "$DOCKER_CONFIG"/cli-plugins
                        curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o "$DOCKER_CONFIG"/cli-plugins/docker-compose
                        chmod +x "$DOCKER_CONFIG"/cli-plugins/docker-compose
                        systemctl start docker
                        systemctl enable docker
                        if [ -x "$(command -v docker)" ]; then
                            echo "Docker installed successfully."
                        else
                            echo "Failed to install Docker with pacman. Try to install it manually."
                            echo "Please visit https://wiki.archlinux.org/title/docker for more information."
                            exit
                        fi
                    else
                        # Automated Docker installation
                        curl https://releases.rancher.com/install-docker/${DOCKER_VERSION}.sh | sh
                        if [ -x "$(command -v docker)" ]; then
                            echo "Docker installed successfully."
                        else
                            echo "Docker installation failed with Rancher script. Trying with official script."
                            curl https://get.docker.com | sh -s -- --version ${DOCKER_VERSION}
                            if [ -x "$(command -v docker)" ]; then
                                echo "Docker installed successfully."
                            else
                                echo "Docker installation failed with official script."
                                echo "Maybe your OS is not supported?"
                                echo "Please visit https://docs.docker.com/engine/install/ and install Docker manually to continue."
                                exit 1
                            fi
                        fi
                    fi
                fi
            fi
            set -e
        fi
    fi

    echo -e "-------------"
    echo -e "Check Docker Configuration..."
    mkdir -p /etc/docker
    # shellcheck disable=SC2015
    test -s /etc/docker/daemon.json && cp /etc/docker/daemon.json /etc/docker/daemon.json.original-"$DATE" || cat >/etc/docker/daemon.json <<EOL
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  }
}
EOL
    cat >/etc/docker/daemon.json.coolify <<EOL
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  }
}
EOL

    TEMP_FILE=$(mktemp)

    if ! jq -s '.[0] * .[1]' /etc/docker/daemon.json /etc/docker/daemon.json.coolify >"$TEMP_FILE"; then
        echo "${red}Error merging JSON files${normal}"
        exit 1
    fi

    mv "$TEMP_FILE" /etc/docker/daemon.json

    if [ -s /etc/docker/daemon.json.original-"$DATE" ]; then
        DIFF=$(diff <(jq --sort-keys . /etc/docker/daemon.json) <(jq --sort-keys . /etc/docker/daemon.json.original-"$DATE"))
        if [ "$DIFF" != "" ]; then
            echo "Docker configuration updated, restart docker daemon..."
            systemctl restart docker
        else
            echo "Docker configuration is up to date."
        fi
    else
        echo "Docker configuration updated, restart docker daemon..."
        systemctl restart docker
    fi

    echo -e "-------------"
}

merge_environment_variables() {
    # Copy .env.example if .env does not exist
    if [ ! -f "$COOLIFY_ROOT_PATH"/source/.env ]; then
        cp "$COOLIFY_ROOT_PATH"/source/.env.production "$COOLIFY_ROOT_PATH"/source/.env
        sed -i "s|APP_ID=.*|APP_ID=$(openssl rand -hex 16)|g" "$COOLIFY_ROOT_PATH"/source/.env
        sed -i "s|APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|g" "$COOLIFY_ROOT_PATH"/source/.env
        sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -base64 32)|g" "$COOLIFY_ROOT_PATH"/source/.env
        sed -i "s|REDIS_PASSWORD=.*|REDIS_PASSWORD=$(openssl rand -base64 32)|g" "$COOLIFY_ROOT_PATH"/source/.env
        sed -i "s|PUSHER_APP_ID=.*|PUSHER_APP_ID=$(openssl rand -hex 32)|g" "$COOLIFY_ROOT_PATH"/source/.env
        sed -i "s|PUSHER_APP_KEY=.*|PUSHER_APP_KEY=$(openssl rand -hex 32)|g" "$COOLIFY_ROOT_PATH"/source/.env
        sed -i "s|PUSHER_APP_SECRET=.*|PUSHER_APP_SECRET=$(openssl rand -hex 32)|g" "$COOLIFY_ROOT_PATH"/source/.env
    fi

    # Merge .env and .env.production. New values will be added to .env
    sort -u -t '=' -k 1,1 "$COOLIFY_ROOT_PATH"/source/.env "$COOLIFY_ROOT_PATH"/source/.env.production | sed '/^$/d' >"$COOLIFY_ROOT_PATH"/source/.env.temp && mv "$COOLIFY_ROOT_PATH"/source/.env.temp "$COOLIFY_ROOT_PATH"/source/.env

    # Merge AUTOUPDATE
    if [ "$AUTOUPDATE" = "false" ]; then
        if ! grep -q "AUTOUPDATE=" "$COOLIFY_ROOT_PATH"/source/.env; then
            echo "AUTOUPDATE=false" >>"$COOLIFY_ROOT_PATH"/source/.env
        else
            sed -i "s|AUTOUPDATE=.*|AUTOUPDATE=false|g" "$COOLIFY_ROOT_PATH"/source/.env
        fi
    fi

    # Merge COOLIFY_ROOT_PATH
    if ! grep -q "COOLIFY_ROOT_PATH=" "$COOLIFY_ROOT_PATH"/source/.env; then
        echo "COOLIFY_ROOT_PATH=$COOLIFY_ROOT_PATH" >>"$COOLIFY_ROOT_PATH"/source/.env
    else
        sed -i "s|COOLIFY_ROOT_PATH=.*|COOLIFY_ROOT_PATH=$COOLIFY_ROOT_PATH|g" "$COOLIFY_ROOT_PATH"/source/.env
    fi
}

generate_ssh_key() {
    # Generate an ssh key (ed25519) at /ssh/keys/id.root@host.docker.internal
    if [ ! -f "$COOLIFY_ROOT_PATH"/ssh/keys/id.root@host.docker.internal ]; then
        ssh-keygen -t ed25519 -a 100 -f "$COOLIFY_ROOT_PATH"/ssh/keys/id.root@host.docker.internal -q -N "" -C root@coolify
        chown 9999 "$COOLIFY_ROOT_PATH"/ssh/keys/id.root@host.docker.internal
    fi

    if [ ! -f ~/.ssh/authorized_keys ]; then
        mkdir -p ~/.ssh
        chmod 700 ~/.ssh
        touch ~/.ssh/authorized_keys
        add_ssh_key
    fi

    if ! grep -qw "root@coolify" ~/.ssh/authorized_keys; then
        add_ssh_key
    fi
}

check_colors() {
    # check if stdout is a terminal
    if test -t 1; then

        # see if it supports colors.
        ncolors=$(tput colors)

        if test -n "$ncolors" && test "$ncolors" -ge 8; then
            bold="$(tput bold)"
            # underline="$(tput smul)"
            # standout="$(tput smso)"
            normal="$(tput sgr0)"
            # black="$(tput setaf 0)"
            red="$(tput setaf 1)"
            # green="$(tput setaf 2)"
            yellow="$(tput setaf 3)"
            # blue="$(tput setaf 4)"
            magenta="$(tput setaf 5)"
            # cyan="$(tput setaf 6)"
            # white="$(tput setaf 7)"
        fi
    fi
}

coolify_logo() {
    cat <<EOF
${magenta}   _____            _ _  __        ${normal}
${magenta}  / ____|          | (_)/ _|       ${normal}
${magenta} | |     ___   ___ | |_| |_ _   _  ${normal}
${magenta} | |    / _ \ / _ \| | |  _| | | | ${normal}
${magenta} | |___| (_) | (_) | | | | | |_| | ${normal}
${magenta}  \_____\___/ \___/|_|_|_|  \__, | ${normal}
${magenta}                             __/ | ${normal}
${magenta}                            |___/  ${normal}
EOF
}

# ---------------------
# END FUNCTIONS AND HELPERS
# ---------------------
#endregion

main() {

    early_checks_and_warnings
    rewrite_and_check_os_type

    # Early dependencies
    # Install xargs on Amazon Linux 2023 - lol
    if [ "$OS_TYPE" = 'amzn' ]; then
        dnf install -y findutils >/dev/null
    fi

    # Overwrite LATEST_VERSION if user pass a version number or if empty use latest version
    if [ "$1" != "" ]; then
        LATEST_VERSION=$1
        LATEST_VERSION="${LATEST_VERSION,,}"
        LATEST_VERSION="${LATEST_VERSION#v}"
    else
        LATEST_VERSION=$(curl --silent $CDN/versions.json | grep -i version | xargs | awk '{print $2}' | tr -d ',')
    fi

    welcome_message
    install_dependencies
    detect_openssh_server
    detect_ssh_permit_root_login
    install_docker

    mkdir -p "$COOLIFY_ROOT_PATH"/{source,ssh,applications,databases,backups,services,proxy,webhooks-during-maintenance,metrics,logs}
    mkdir -p "$COOLIFY_ROOT_PATH"/ssh/{keys,mux}
    mkdir -p "$COOLIFY_ROOT_PATH"/proxy/dynamic

    chown -R 9999:root "$COOLIFY_ROOT_PATH"
    chmod -R 700 "$COOLIFY_ROOT_PATH"

    echo "Downloading required files from CDN..."
    curl -fsSL $CDN/docker-compose.yml -o "$COOLIFY_ROOT_PATH"/source/docker-compose.yml
    curl -fsSL $CDN/docker-compose.prod.yml -o "$COOLIFY_ROOT_PATH"/source/docker-compose.prod.yml
    curl -fsSL $CDN/.env.production -o "$COOLIFY_ROOT_PATH"/source/.env.production
    curl -fsSL $CDN/upgrade.sh -o "$COOLIFY_ROOT_PATH"/source/upgrade.sh

    merge_environment_variables

    generate_ssh_key

    # RUN UPGRADE
    bash "$COOLIFY_ROOT_PATH"/source/upgrade.sh "${LATEST_VERSION:-latest}"

    echo -e "\nCongratulations! Your Coolify instance is ready to use.\n"
    echo "Please visit http://$(curl -4s https://ifconfig.io):8000 to get started."
}

main "$@"
