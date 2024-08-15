#!/bin/sh

# Install openssh-client
apk add --no-cache openssh-client make g++ python3

cd /terminal

# Install npm dependencies
npm ci

# Rebuild node-pty
npm rebuild node-pty --update-binary

# Function to timestamp logs
timestamp() {
    date "+%Y-%m-%d %H:%M:%S"
}

# Start the terminal server in the background with logging
node --watch /terminal/terminal-server.js > >(while read line; do echo "$(timestamp) [TERMINAL] $line"; done) 2>&1 &
TERMINAL_PID=$!

# Start the Soketi process in the background with logging
node /app/bin/server.js start > >(while read line; do echo "$(timestamp) [SOKETI] $line"; done) 2>&1 &
SOKETI_PID=$!

# Function to forward signals to child processes
forward_signal() {
    kill -$1 $TERMINAL_PID $SOKETI_PID
}

# Forward SIGTERM to child processes
trap 'forward_signal TERM' TERM

# Wait for any process to exit
wait -n

# Exit with status of process that exited first
exit $?