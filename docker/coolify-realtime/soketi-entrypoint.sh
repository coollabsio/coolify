#!/bin/sh

if [ "$1" = "watch" ]; then
    WATCH_MODE="--watch"
else
    WATCH_MODE=""
fi

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [ENTRYPOINT] $*"
}

start_logger() {
    prefix="$1"
    fifo_path="$2"

    while read -r line; do
        echo "$(date '+%Y-%m-%d %H:%M:%S') [$prefix] $line"
    done < "$fifo_path" &
}

cleanup() {
    rm -f "$TERMINAL_LOG_FIFO" "$SOKETI_LOG_FIFO"
}

TERMINAL_LOG_FIFO="/tmp/coolify-terminal-log.$$"
SOKETI_LOG_FIFO="/tmp/coolify-soketi-log.$$"

rm -f "$TERMINAL_LOG_FIFO" "$SOKETI_LOG_FIFO"
mkfifo "$TERMINAL_LOG_FIFO" "$SOKETI_LOG_FIFO"

trap cleanup EXIT

log "Starting realtime container"
log "WATCH_MODE=${WATCH_MODE:-off}"
log "SOKETI_DEBUG=${SOKETI_DEBUG:-unset}"
log "NODE_OPTIONS=${NODE_OPTIONS:-unset}"

start_logger "TERMINAL" "$TERMINAL_LOG_FIFO"
TERMINAL_LOGGER_PID=$!

start_logger "SOKETI" "$SOKETI_LOG_FIFO"
SOKETI_LOGGER_PID=$!

node $WATCH_MODE /terminal/terminal-server.js > "$TERMINAL_LOG_FIFO" 2>&1 &
TERMINAL_PID=$!

log "Terminal server started pid=$TERMINAL_PID logger_pid=$TERMINAL_LOGGER_PID"

node /app/bin/server.js start > "$SOKETI_LOG_FIFO" 2>&1 &
SOKETI_PID=$!

log "Soketi started pid=$SOKETI_PID logger_pid=$SOKETI_LOGGER_PID"

forward_signal() {
    log "Forwarding signal $1 to terminal=$TERMINAL_PID soketi=$SOKETI_PID"

    kill -"$1" "$TERMINAL_PID" 2>/dev/null || true
    kill -"$1" "$SOKETI_PID" 2>/dev/null || true
}

trap 'forward_signal TERM' TERM
trap 'forward_signal INT' INT

while true; do
    if ! kill -0 "$TERMINAL_PID" 2>/dev/null; then
        wait "$TERMINAL_PID"
        EXIT_CODE=$?

        log "Terminal server exited code=$EXIT_CODE; stopping soketi pid=$SOKETI_PID"

        kill "$SOKETI_PID" 2>/dev/null || true
        wait "$SOKETI_PID" 2>/dev/null || true

        exit "$EXIT_CODE"
    fi

    if ! kill -0 "$SOKETI_PID" 2>/dev/null; then
        wait "$SOKETI_PID"
        EXIT_CODE=$?

        log "Soketi exited code=$EXIT_CODE; stopping terminal pid=$TERMINAL_PID"

        kill "$TERMINAL_PID" 2>/dev/null || true
        wait "$TERMINAL_PID" 2>/dev/null || true

        exit "$EXIT_CODE"
    fi

    sleep 1
done
