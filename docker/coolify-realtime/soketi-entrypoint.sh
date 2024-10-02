#!/bin/sh
# Function to timestamp logs

# Check if the first argument is 'watch'
if [ "$1" = "watch" ]; then
    WATCH_MODE="--watch"
else
    WATCH_MODE=""
fi

timestamp() {
    date "+%Y-%m-%d %H:%M:%S"
}

# Start the terminal server in the background with logging
node $WATCH_MODE /terminal/terminal-server.js > >(while read line; do echo "$(timestamp) [TERMINAL] $line"; done) 2>&1 &
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
