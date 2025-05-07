# Versions
# https://github.com/soketi/soketi/releases
ARG SOKETI_VERSION=1.6-16-alpine
# https://github.com/cloudflare/cloudflared/releases
ARG CLOUDFLARED_VERSION=2025.2.0

FROM quay.io/soketi/soketi:${SOKETI_VERSION}

ARG TARGETPLATFORM
ARG CLOUDFLARED_VERSION

WORKDIR /terminal
RUN apk add --no-cache openssh-client make g++ python3 curl
COPY docker/coolify-realtime/package.json ./
RUN npm i
RUN npm rebuild node-pty --update-binary
COPY docker/coolify-realtime/soketi-entrypoint.sh /soketi-entrypoint.sh
COPY docker/coolify-realtime/terminal-server.js /terminal/terminal-server.js

# Install Cloudflared based on architecture
RUN if [ "${TARGETPLATFORM}" = "linux/amd64" ]; then \
        curl -sSL "https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-amd64" -o /usr/local/bin/cloudflared; \
    elif [ "${TARGETPLATFORM}" = "linux/arm64" ]; then \
        curl -sSL "https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-arm64" -o /usr/local/bin/cloudflared; \
    fi && \
    chmod +x /usr/local/bin/cloudflared

ENTRYPOINT ["/bin/sh", "/soketi-entrypoint.sh"]
