#!/usr/bin/env bash
#
# webhook-tunnel.sh
#
# Opens a Cloudflare quick tunnel to expose your local Coolify instance
# so GitHub can deliver webhook events during local development.
#
# Usage:
#   bash scripts/webhook-tunnel.sh [local_port]
#
# Default local port: 8000

set -euo pipefail

LOCAL_PORT="${1:-8000}"
WEBHOOK_PATH="/source/github/events"

if ! command -v cloudflared &>/dev/null; then
  echo ""
  echo "  ERROR: cloudflared is not installed."
  echo "  Install it with: brew install cloudflared"
  echo ""
  exit 1
fi

echo ""
echo "  GitHub Webhook Tunnel (via Cloudflare)"
echo "  ======================================="
echo "  Forwarding: public HTTPS -> localhost:${LOCAL_PORT}"
echo ""
echo "  Waiting for tunnel URL..."
echo ""

cloudflared tunnel --url "http://localhost:${LOCAL_PORT}" 2>&1 | while IFS= read -r line; do
  if [[ "$line" =~ https://[a-zA-Z0-9_-]+\.trycloudflare\.com ]]; then
    TUNNEL_URL="${BASH_REMATCH[0]}"
    echo ""
    echo "  ✔  Tunnel is live!"
    echo ""
    echo "  ┌─────────────────────────────────────────────────────────────────┐"
    echo "  │  Webhook endpoint:                                               │"
    echo "  │  ${TUNNEL_URL}${WEBHOOK_PATH}"
    echo "  │                                                                  │"
    echo "  │  Configure your GitHub App:                                      │"
    echo "  │  GitHub -> App Settings -> Webhook URL -> paste the URL above   │"
    echo "  │                                                                  │"
    echo "  │  Press Ctrl+C to stop the tunnel.                               │"
    echo "  └─────────────────────────────────────────────────────────────────┘"
    echo ""
  fi
done
