# Supabase MCP (Model Context Protocol) Setup Guide for Coolify

## Overview

The MCP (Model Context Protocol) server in self-hosted Supabase allows AI tools like Claude Code, Cursor, and Windsurf to interact with your Supabase instance. By default, MCP access is **disabled for security** as it should not be exposed to the public internet.

This guide explains how to securely enable and use MCP with Coolify's Supabase template.

---

## ⚠️ Security Warning

**NEVER expose the `/mcp` endpoint directly to the internet without authentication.** The MCP server does not offer OAuth 2.1 authentication. Always use:
- SSH tunnels (recommended)
- Wireguard VPN (recommended)
- IP restrictions with reverse proxy

---

## Quick Start: Enable MCP Access

### Step 1: Edit Your Supabase Service in Coolify

1. Navigate to your Supabase service in Coolify
2. Go to **Compose Editor** or **Environment Variables**
3. Find the `supabase-kong` service configuration
4. Locate the Kong configuration in the bind mount content (search for `volumes/api/kong.yml`)

### Step 2: Enable MCP Endpoint

In the Kong configuration, find the MCP section:

```yaml
## MCP endpoint - local/secure access only
- name: mcp
  _comment: 'MCP: /mcp -> http://studio:3000/api/mcp (local access only)'
  url: http://supabase-studio:3000/api/mcp
  routes:
    - name: mcp
      strip_path: true
      paths:
        - /mcp
  plugins:
    - name: cors
    # By default, block external access to MCP for security
    # To enable: Set ENABLE_MCP_ACCESS=true and configure ALLOWED_MCP_IPS
    - name: request-termination
      config:
        status_code: 403
        message: "MCP access is disabled. See documentation to enable secure access."
```

**Replace** the `request-termination` plugin with `ip-restriction`:

```yaml
## MCP endpoint - local/secure access only
- name: mcp
  _comment: 'MCP: /mcp -> http://studio:3000/api/mcp (local access only)'
  url: http://supabase-studio:3000/api/mcp
  routes:
    - name: mcp
      strip_path: true
      paths:
        - /mcp
  plugins:
    - name: cors
    - name: ip-restriction
      config:
        allow:
          - 127.0.0.1
          - ::1
          # Add your Docker bridge gateway IP (see Step 3)
          # - 172.18.0.1
          # Add your Wireguard VPN subnet if using VPN
          # - 10.8.0.0/24
        # IMPORTANT: Do not remove deny!
        deny: []
```

### Step 3: Find Your Docker Bridge Gateway IP

On your Coolify server, run:

```bash
docker inspect <your-supabase-kong-container-name> \
  --format '{{range .NetworkSettings.Networks}}{{println .Gateway}}{{end}}'
```

Example output: `172.18.0.1`

Add this IP to the `allow` list in the Kong configuration above.

### Step 4: Restart Kong Container

After saving the configuration in Coolify, restart the Supabase service or just the Kong container.

---

## Method 1: SSH Tunnel (Recommended for Individual Use)

### On Your Local Machine

Create an SSH tunnel to forward local port 8080 to your Supabase Kong container:

```bash
ssh -L localhost:8080:localhost:8000 you@your-coolify-server
```

This forwards:
- Local port `8080` → Remote port `8000` (Kong's port)

Keep this terminal open while using MCP.

### Test the Connection

```bash
curl http://localhost:8080/mcp \
  -X POST \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -H "MCP-Protocol-Version: 2025-06-18" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "initialize",
    "params": {
      "protocolVersion": "2025-06-18",
      "capabilities": {
        "elicitation": {}
      },
      "clientInfo": {
        "name": "test-client",
        "title": "Test Client",
        "version": "1.0.0"
      }
    }
  }'
```

You should receive a JSON response with server capabilities.

---

## Method 2: Wireguard VPN (Recommended for Teams)

### 1. Install Wireguard on Coolify Server

```bash
# Ubuntu/Debian
sudo apt update && sudo apt install wireguard

# Generate server keys
wg genkey | tee /etc/wireguard/server_private.key | wg pubkey > /etc/wireguard/server_public.key
```

### 2. Configure Wireguard Server

Create `/etc/wireguard/wg0.conf`:

```ini
[Interface]
Address = 10.8.0.1/24
ListenPort = 51820
PrivateKey = <your-server-private-key>
PostUp = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o eth0 -j MASQUERADE
PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o eth0 -j MASQUERADE

# Client 1
[Peer]
PublicKey = <client-1-public-key>
AllowedIPs = 10.8.0.2/32

# Add more peers as needed
```

### 3. Add Wireguard Subnet to Kong IP Restriction

In the MCP Kong configuration, add the Wireguard subnet:

```yaml
- name: ip-restriction
  config:
    allow:
      - 127.0.0.1
      - ::1
      - 172.18.0.1  # Docker bridge
      - 10.8.0.0/24  # Wireguard VPN
    deny: []
```

### 4. Configure Wireguard Client

On your local machine, create a Wireguard configuration and connect. Once connected, you can access MCP at:

```
http://your-coolify-server-ip:8000/mcp
```

---

## Configuring AI Tools

### Claude Code (OpenClaw)

In your `~/.openclaw/mcp.json` or MCP settings:

```json
{
  "mcpServers": {
    "supabase-production": {
      "url": "http://localhost:8080/mcp"
    }
  }
}
```

### Cursor

Edit Cursor settings (`Cmd/Ctrl + ,`) → Search for "MCP" → Add:

```json
{
  "mcp.servers": {
    "supabase-production": {
      "url": "http://localhost:8080/mcp"
    }
  }
}
```

### Windsurf

In Windsurf settings:

```json
{
  "servers": {
    "supabase-production": {
      "url": "http://localhost:8080/mcp"
    }
  }
}
```

---

## Multiple Supabase Instances on Same Coolify Server

### The Problem

When running multiple Supabase instances on the same Coolify server, they all:
- Use internal Docker networks
- Expose Kong on different external URLs via Traefik
- Need unique MCP access paths

### Solution: SSH Tunnel with Different Local Ports

For each Supabase instance, create a separate SSH tunnel with unique local ports:

#### Instance 1: Production
```bash
ssh -L localhost:8080:supabase-production-kong:8000 you@coolify-server
```

#### Instance 2: Staging
```bash
ssh -L localhost:8081:supabase-staging-kong:8000 you@coolify-server
```

#### Instance 3: Development
```bash
ssh -L localhost:8082:supabase-dev-kong:8000 you@coolify-server
```

**Note:** Replace `supabase-production-kong`, `supabase-staging-kong`, etc. with your actual Kong container names from Coolify.

### Configure Multiple MCP Servers

In your AI tool's MCP configuration:

```json
{
  "mcpServers": {
    "supabase-production": {
      "url": "http://localhost:8080/mcp"
    },
    "supabase-staging": {
      "url": "http://localhost:8081/mcp"
    },
    "supabase-development": {
      "url": "http://localhost:8082/mcp"
    }
  }
}
```

Now you can specify which Supabase instance to use:
- "Use the production Supabase MCP to check the schema"
- "Connect to staging Supabase and create a test table"

---

## Alternative: Traefik Labels (Advanced)

If you want to expose MCP via Coolify's Traefik with better path handling:

### Add Custom Labels to supabase-kong Service

In Coolify's compose editor, add these labels to `supabase-kong`:

```yaml
supabase-kong:
  image: kong:2.8.1
  # ... existing config ...
  labels:
    - "traefik.http.routers.supabase-mcp-${SERVICE_ID}.rule=Host(`${SERVICE_FQDN_SUPABASEKONG}`) && PathPrefix(`/mcp`)"
    - "traefik.http.routers.supabase-mcp-${SERVICE_ID}.entrypoints=https"
    - "traefik.http.routers.supabase-mcp-${SERVICE_ID}.middlewares=supabase-mcp-ipwhitelist-${SERVICE_ID}"
    - "traefik.http.middlewares.supabase-mcp-ipwhitelist-${SERVICE_ID}.ipwhitelist.sourcerange=YOUR_VPN_IP_RANGE"
```

Replace `YOUR_VPN_IP_RANGE` with your VPN subnet (e.g., `10.8.0.0/24`).

This exposes MCP at:
```
https://your-supabase-domain.com/mcp
```

**⚠️ WARNING:** Only use this method with proper IP whitelisting via Traefik!

---

## Troubleshooting

### MCP Returns 403 Forbidden
- Check that you've enabled MCP in Kong config (removed `request-termination` plugin)
- Verify your Docker bridge gateway IP is in the `allow` list
- Restart Kong container after configuration changes

### SSH Tunnel Connection Refused
- Verify Kong container is running
- Check that Kong is listening on port 8000 inside the container
- Ensure your SSH user has access to the Docker network

### Multiple Instances Not Working
- Verify each SSH tunnel uses a different local port
- Check Kong container names are correct
- Ensure each instance has MCP enabled in its Kong config

### MCP Client Shows "Connection Failed"
- Test the connection with `curl` first (see test command above)
- Check MCP client configuration syntax
- Verify SSH tunnel is still active
- Check Kong logs: `docker logs <kong-container-name>`

---

## Security Best Practices

1. **Never expose MCP to the public internet** without authentication
2. **Use SSH tunnels or Wireguard VPN** for secure access
3. **Limit IP ranges** in Kong's ip-restriction plugin
4. **Rotate credentials regularly** (Supabase service keys, JWT secrets)
5. **Monitor access logs** via Kong and Traefik
6. **Use separate instances** for production, staging, and development

---

## References

- [Official Supabase MCP Documentation](https://supabase.com/docs/guides/self-hosting/enable-mcp)
- [Model Context Protocol Specification](https://modelcontextprotocol.io/)
- [Kong IP Restriction Plugin](https://docs.konghq.com/hub/kong-inc/ip-restriction/)
- [Wireguard Documentation](https://www.wireguard.com/quickstart/)

---

## Contributing

Found an issue or have an improvement? Please open an issue or PR on the Coolify repository referencing issue #7458.

**Bounty:** This guide was created for the Coolify GitHub bounty #7458 ($15).

