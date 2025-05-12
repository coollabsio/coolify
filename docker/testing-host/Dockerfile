# Versions
# https://hub.docker.com/_/debian/
ARG BASE_OS_VERSION=12-slim
# https://github.com/moby/moby
ARG DOCKER_VERSION=28.1.1
# https://github.com/just-containers/s6-overlay/releases
ARG S6_OVERLAY_VERSION=3.2.1.0

FROM debian:${BASE_OS_VERSION} AS s6-builder

ARG S6_OVERLAY_VERSION
ARG TARGETPLATFORM

# Install dependencies for downloading and extracting s6-overlay
RUN apt-get update && apt-get install -y \
    curl \
    xz-utils

# Add S6 Overlay
RUN mkdir -p /s6/downloads /s6/extracted && \
    if [ "${TARGETPLATFORM}" = "linux/amd64" ]; then \
        curl -fsSL "https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz" -o /s6/downloads/s6-overlay-noarch.tar.xz && \
        curl -fsSL "https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz.sha256" -o /s6/downloads/s6-overlay-noarch.tar.xz.sha256 && \
        curl -fsSL "https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-x86_64.tar.xz" -o /s6/downloads/s6-overlay-x86_64.tar.xz && \
        curl -fsSL "https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-x86_64.tar.xz.sha256" -o /s6/downloads/s6-overlay-x86_64.tar.xz.sha256 && \
        cd /s6/downloads && \
        sha256sum -c s6-overlay-noarch.tar.xz.sha256 && \
        sha256sum -c s6-overlay-x86_64.tar.xz.sha256 && \
        tar -C /s6/extracted -Jxpf /s6/downloads/s6-overlay-noarch.tar.xz && \
        tar -C /s6/extracted -Jxpf /s6/downloads/s6-overlay-x86_64.tar.xz; \
    elif [ "${TARGETPLATFORM}" = "linux/arm64" ]; then \
        curl -fsSL "https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz" -o /s6/downloads/s6-overlay-noarch.tar.xz && \
        curl -fsSL "https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz.sha256" -o /s6/downloads/s6-overlay-noarch.tar.xz.sha256 && \
        curl -fsSL "https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-aarch64.tar.xz" -o /s6/downloads/s6-overlay-aarch64.tar.xz && \
        curl -fsSL "https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-aarch64.tar.xz.sha256" -o /s6/downloads/s6-overlay-aarch64.tar.xz.sha256 && \
        cd /s6/downloads && \
        sha256sum -c s6-overlay-noarch.tar.xz.sha256 && \
        sha256sum -c s6-overlay-aarch64.tar.xz.sha256 && \
        tar -C /s6/extracted -Jxpf /s6/downloads/s6-overlay-noarch.tar.xz && \
        tar -C /s6/extracted -Jxpf /s6/downloads/s6-overlay-aarch64.tar.xz; \
    fi

# Final stage
FROM debian:${BASE_OS_VERSION} AS final

ARG DOCKER_VERSION
ARG TARGETPLATFORM

RUN apt-get update && apt-get install -y \
    ca-certificates \
    curl \
    openssh-server \
    openssl \
    qemu-user-static \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && rm -rf /tmp/* /var/tmp/*

# Add Docker's official GPG key
RUN apt-get update && \
    install -m 0755 -d /etc/apt/keyrings && \
    curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc && \
    chmod a+r /etc/apt/keyrings/docker.asc

# Add the repository to Apt sources
RUN echo \
    "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian \
    $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
    tee /etc/apt/sources.list.d/docker.list > /dev/null && \
    apt-get update

# Install a specific version of Docker
RUN VERSION_STRING="5:${DOCKER_VERSION}-1~debian.12~bookworm" && \
    apt-get update && apt-get install -y \
    docker-ce=$VERSION_STRING \
    docker-ce-cli=$VERSION_STRING \
    containerd.io \
    docker-buildx-plugin \
    docker-compose-plugin \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && rm -rf /tmp/* /var/tmp/*

# Set up Development SSH
RUN ssh-keygen -A && \
    mkdir -p /run/sshd && \
    mkdir -p ~/.ssh && \
    echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFuGmoeGq/pojrsyP1pszcNVuZx9iFkCELtxrh31QJ68 root@coolify-dev" >> ~/.ssh/authorized_keys

# Copy s6-overlay from s6-builder stage
COPY --from=s6-builder /s6/extracted /

# Set up s6-overlay
COPY --chmod=755 docker/testing-host/etc/s6-overlay/ /etc/s6-overlay/
RUN chmod +x /etc/s6-overlay/scripts/wait-for-docker.sh

EXPOSE 22

ENTRYPOINT ["/init"]
