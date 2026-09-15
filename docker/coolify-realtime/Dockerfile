# Versions
# https://github.com/soketi/soketi/releases
ARG SOKETI_VERSION=1.6-16-alpine
# https://github.com/cloudflare/cloudflared/releases
ARG CLOUDFLARED_VERSION=2025.7.0

FROM quay.io/soketi/soketi:${SOKETI_VERSION}

ARG TARGETPLATFORM
ARG CLOUDFLARED_VERSION

WORKDIR /terminal
RUN apk upgrade --no-cache && \
    apk add --no-cache openssh-client make g++ python3 curl
COPY --from=oven/bun:1-alpine /usr/local/bin/bun /usr/local/bin/bun
COPY docker/coolify-realtime/package.json docker/coolify-realtime/bun.lock ./
# node-pty is a trusted dependency (see package.json) so its native addon is
# compiled from source against the image's Node runtime during install.
RUN bun install --frozen-lockfile
COPY docker/coolify-realtime/soketi-entrypoint.sh /soketi-entrypoint.sh
COPY docker/coolify-realtime/terminal-server.js /terminal/terminal-server.js
COPY docker/coolify-realtime/terminal-utils.js /terminal/terminal-utils.js

# Install Cloudflared based on architecture
RUN if [ "${TARGETPLATFORM}" = "linux/amd64" ]; then \
        curl -sSL "https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-amd64" -o /usr/local/bin/cloudflared; \
    elif [ "${TARGETPLATFORM}" = "linux/arm64" ]; then \
        curl -sSL "https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-arm64" -o /usr/local/bin/cloudflared; \
    fi && \
    chmod +x /usr/local/bin/cloudflared

ENTRYPOINT ["/bin/sh", "/soketi-entrypoint.sh"]
