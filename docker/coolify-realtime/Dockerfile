FROM quay.io/soketi/soketi:1.6-16-alpine


ARG TARGETPLATFORM
# https://github.com/cloudflare/cloudflared/releases
ARG CLOUDFLARED_VERSION=2024.4.1

WORKDIR /terminal
RUN apk add --no-cache openssh-client make g++ python3 curl
COPY docker/coolify-realtime/package.json ./
RUN npm i
RUN npm rebuild node-pty --update-binary
COPY docker/coolify-realtime/soketi-entrypoint.sh /soketi-entrypoint.sh
COPY docker/coolify-realtime/terminal-server.js /terminal/terminal-server.js

RUN /bin/sh -c "if [[ ${TARGETPLATFORM} == 'linux/amd64' ]]; then \
    echo 'amd64' && \
    curl -sSL https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-amd64 -o /usr/local/bin/cloudflared && chmod +x /usr/local/bin/cloudflared \
    ;fi"

RUN /bin/sh -c "if [[ ${TARGETPLATFORM} == 'linux/arm64' ]]; then \
    echo 'arm64' && \
    curl -L https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-arm64 -o /usr/local/bin/cloudflared && chmod +x /usr/local/bin/cloudflared \
    ;fi"

ENTRYPOINT ["/bin/sh", "/soketi-entrypoint.sh"]
