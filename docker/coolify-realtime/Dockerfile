FROM quay.io/soketi/soketi:1.6-16-alpine
WORKDIR /terminal
RUN apk add --no-cache openssh-client make g++ python3
COPY docker/coolify-realtime/package.json ./
RUN npm i
RUN npm rebuild node-pty --update-binary
COPY docker/coolify-realtime/soketi-entrypoint.sh /soketi-entrypoint.sh
COPY docker/coolify-realtime/terminal-server.js /terminal/terminal-server.js
ENTRYPOINT ["/bin/sh", "/soketi-entrypoint.sh"]
