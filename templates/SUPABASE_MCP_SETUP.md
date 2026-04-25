# Supabase MCP Setup Guide for Coolify

> **Issue**: [#7458](https://github.com/coollabsio/coolify/issues/7458) — Official Self-Hosted Supabase MCP Setup hindered by Coolify

This guide covers everything you need to securely enable and use the Supabase MCP (Model Context Protocol) server when running Supabase through Coolify.

---

## Table of Contents

1. [Choosing the Right Template](#choosing-the-right-template)
2. [Understanding the Problem](#understanding-the-problem)
3. [How MCP Works in Coolify's Supabase Template](#how-mcp-works-in-coolifys-supabase-template)
4. [Method 1: SSH Tunnel (Simplest)](#method-1-ssh-tunnel-simplest)
5. [Method 2: WireGuard VPN (Recommended for Production)](#method-2-wireguard-vpn-recommended-for-production)
6. [Method 3: Local Network Access](#method-3-local-network-access)
7. [Multi-Instance Setup (Multiple Supabase on Same Server)](#multi-instance-setup-multiple-supabase-on-same-server)
8. [IDE Configuration (Cursor, Claude Code, Windsurf)](#ide-configuration-cursor-claude-code-windsurf)
9. [Troubleshooting](#troubleshooting)

---

## Choosing the Right Template

Coolify provides **two Supabase templates**:

| Template | Use Case | MCP Access |
|---|---|---|
| **`supabase`** (default) | Standard deployment | 🔒 Locked to localhost only. Requires `MCP_ALLOWED_IPS` env var to enable. |
| **`supabase-with-mcp`** | AI tool integration | ✅ Pre-enabled for private networks (Docker, WireGuard, LAN). Works out of the box. |

**Which to choose?**
- Use **`supabase`** if you want maximum security and don't need MCP, or if you want full control over IP whitelisting.
- Use **`supabase-with-mcp`** if you want MCP to work immediately with SSH tunnels, WireGuard, or local network access without additional configuration.

Both templates support the same `MCP_ALLOWED_IPS` environment variable for custom IP restrictions.

---

## Understanding the Problem

When you deploy Supabase via Coolify, the Kong API gateway is exposed publicly through Traefik. The MCP endpoint (`/mcp`) is routed through Kong to the Supabase Studio container, but it's locked down by an `ip-restriction` Kong plugin that only allows `127.0.0.1` and `::1`.

Since Coolify runs everything in Docker containers, accessing MCP from outside requires either:
- **An SSH tunnel** (forwards your local port through SSH to the Docker host)
- **A WireGuard VPN** (gives your machine an IP on the Docker network)
- **Direct local network access** (with proper IP allowlisting)

Additionally, if you run **multiple Supabase instances** on the same Coolify server, they all share the same `/mcp` path, causing routing conflicts. This guide solves that too.

---

## How MCP Works in Coolify's Supabase Template

The Coolify Supabase template already includes the MCP route in Kong's configuration:

```yaml
## MCP Server - local access only
- name: mcp
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
          $MCP_ALLOWED_IPS    # ← Your custom IPs go here
        deny: []
```

The `$MCP_ALLOWED_IPS` variable is substituted at container startup from the `MCP_ALLOWED_IPS` environment variable you set in Coolify.

---

## Method 1: SSH Tunnel (Simplest)

This method requires no additional services. You create an SSH tunnel from your local machine to the Coolify server.

### Step 1: Find Your Docker Gateway IP

SSH into your Coolify server and run:

```bash
# Find the Supabase project directory (Coolify stores services in /data/...)
# Navigate to your Supabase service directory, then:
docker inspect supabase-kong --format '{{range .NetworkSettings.Networks}}{{println .Gateway}}{{end}}'
```

This outputs something like `172.18.0.1`.

### Step 2: Add the Gateway IP to MCP_ALLOWED_IPS

In the Coolify UI:
1. Go to your Supabase service → **Environment Variables**
2. Add a new variable:
   - **Key**: `MCP_ALLOWED_IPS`
   - **Value**: `- 172.18.0.1` (replace with your actual gateway IP)
3. **Redeploy** the Supabase service

> **Important**: The value must include the YAML list prefix `- ` (dash followed by space). For multiple IPs, separate them with newlines in the Coolify env var UI (one `- IP` per line).

### Step 3: Create the SSH Tunnel

From your local machine:

```bash
ssh -L 8080:localhost:8000 your-user@your-coolify-server
```

This forwards `localhost:8080` on your machine to port `8000` (Kong) on the Coolify server.

### Step 4: Verify the Tunnel

```bash
curl http://localhost:8080/mcp \
  -X POST \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "initialize",
    "params": {
      "protocolVersion": "2025-06-18",
      "capabilities": {},
      "clientInfo": { "name": "test", "version": "1.0.0" }
    }
  }'
```

A successful response means the MCP server is reachable.

---

## Method 2: WireGuard VPN (Recommended for Production)

WireGuard gives you persistent, secure access to your Coolify server's internal network without exposing anything to the public internet.

### Step 1: Deploy WireGuard in Coolify

1. In Coolify, go to **+ New Resource** → **Services**
2. Search for **WireGuard Easy** and deploy it
3. Note the WireGuard subnet (default: `10.8.0.0/24`)
4. Download the WireGuard config for your client from the WireGuard Easy web UI (usually at `http://your-coolify-server:51821`)

### Step 2: Add WireGuard Subnet to MCP_ALLOWED_IPS

In the Coolify UI for your Supabase service:
1. Go to **Environment Variables**
2. Add or update:
   - **Key**: `MCP_ALLOWED_IPS`
   - **Value**: `- 10.8.0.0/24` (replace with your actual WireGuard subnet)
3. **Redeploy** the Supabase service

### Step 3: Connect via WireGuard

1. Import the WireGuard config into your WireGuard client (desktop or mobile)
2. Connect to the VPN
3. Verify you can reach the Supabase Kong endpoint:

```bash
# Replace with your Coolify server's internal IP and the correct port
curl http://172.20.0.1:8000/mcp \
  -X POST \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "initialize",
    "params": {
      "protocolVersion": "2025-06-18",
      "capabilities": {},
      "clientInfo": { "name": "test", "version": "1.0.0" }
    }
  }'
```

### Why WireGuard Over SSH Tunnel?

| Feature | SSH Tunnel | WireGuard |
|---|---|---|
| Setup complexity | Low | Medium |
| Persistent connection | No (breaks on disconnect) | Yes (auto-reconnect) |
| Multiple clients | One tunnel per client | Unlimited clients |
| Performance | Good | Excellent |
| Works with IDEs | Requires keeping terminal open | Background service |

---

## Method 3: Local Network Access

If you're on the same LAN as your Coolify server, you can access MCP directly.

### Step 1: Find Your Machine's LAN IP

```bash
# Linux/macOS
ip addr show | grep "inet "
# or
ifconfig | grep "inet "

# Windows
ipconfig
```

### Step 2: Add Your LAN IP to MCP_ALLOWED_IPS

In Coolify → Supabase service → **Environment Variables**:
- **Key**: `MCP_ALLOWED_IPS`
- **Value**: `- 192.168.1.100` (your LAN IP)

> **Warning**: LAN IPs can change if using DHCP. Consider using a CIDR range like `- 192.168.1.0/24` for your entire subnet, but understand this allows any device on your LAN to access MCP.

### Step 3: Access MCP Directly

```bash
curl http://your-coolify-server-ip:8000/mcp \
  -X POST \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1.0.0"}}}'
```

---

## Multi-Instance Setup (Multiple Supabase on Same Server)

This is the **most important** section if you run multiple Supabase instances.

### The Problem

When you deploy two Supabase services in Coolify, both have Kong listening on port 8000 internally, and both have an `/mcp` route. Traefik routes based on the domain, so if both instances share the same domain, the `/mcp` paths collide.

### Solution: Use Unique Domains Per Instance

Coolify assigns each service a unique FQDN. The MCP endpoint is accessed through the Kong service of each instance. Here's how to route them:

#### Step 1: Identify Each Instance's Domain

In the Coolify UI, each Supabase service has a **Domain** (FQDN) configured for the `supabase-kong` service. Note them:

- Instance A: `supabase-a.yourdomain.com`
- Instance B: `supabase-b.yourdomain.com`

#### Step 2: Access MCP via Each Instance's Domain

Each instance's MCP is accessible at its own domain:

- Instance A MCP: `http://supabase-a.yourdomain.com/mcp`
- Instance B MCP: `http://supabase-b.yourdomain.com/mcp`

When using SSH tunnel or WireGuard, you access each instance through its **internal Docker network**. The key is that each Supabase service runs in its own Docker network, so the containers are isolated.

#### Step 3: For SSH Tunnel — Use Different Local Ports

```bash
# Terminal 1: Instance A
ssh -L 8081:localhost:8000 your-user@your-coolify-server

# Terminal 2: Instance B  
ssh -L 8082:localhost:8000 your-user@your-coolify-server
```

> **Note**: This only works if you have one Supabase instance. For multiple instances, you need to identify the correct container port mapping.

#### Step 4: For Multiple Instances — Use Coolify's Port Mapping

Each Supabase instance in Coolify has its Kong service mapped to a unique external port. Find the port in the Coolify UI under the `supabase-kong` service → **Ports** tab.

If Instance A maps to port `30001` and Instance B maps to port `30002`:

```bash
# Instance A MCP
curl http://your-coolify-server:30001/mcp

# Instance B MCP
curl http://your-coolify-server:30002/mcp
```

#### Step 5: Configure MCP_ALLOWED_IPS Per Instance

Each instance needs its own `MCP_ALLOWED_IPS` environment variable. Set them independently in each service's environment variables in Coolify.

### Summary: Multi-Instance Architecture

```
Coolify Server
├── Supabase Instance A (port 30001)
│   ├── Kong → /mcp → supabase-studio:3000/api/mcp
│   └── MCP_ALLOWED_IPS: - 10.8.0.0/24
│
├── Supabase Instance B (port 30002)
│   ├── Kong → /mcp → supabase-studio:3000/api/mcp
│   └── MCP_ALLOWED_IPS: - 10.8.0.0/24
│
└── WireGuard (10.8.0.0/24)
    └── Both instances accessible via VPN
```

---

## IDE Configuration (Cursor, Claude Code, Windsurf)

### Cursor

1. Open Cursor → **Settings** (⌘+, or Ctrl+,)
2. Search for **MCP**
3. Click **Edit MCP Settings** (opens `mcp.json`)
4. Add your Supabase MCP server:

```json
{
  "mcpServers": {
    "supabase-project-a": {
      "url": "http://localhost:8080/mcp"
    },
    "supabase-project-b": {
      "url": "http://localhost:8081/mcp"
    }
  }
}
```

For **WireGuard** (no tunnel needed):

```json
{
  "mcpServers": {
    "supabase-project-a": {
      "url": "http://172.20.0.1:30001/mcp"
    },
    "supabase-project-b": {
      "url": "http://172.20.0.1:30002/mcp"
    }
  }
}
```

### Claude Code

Create or edit `~/.claude/settings.json`:

```json
{
  "mcpServers": {
    "supabase": {
      "command": "npx",
      "args": ["-y", "@anthropic-ai/mcp-remote", "http://localhost:8080/mcp"]
    }
  }
}
```

Or with direct URL (Claude Code 0.2+):

```json
{
  "mcpServers": {
    "supabase": {
      "url": "http://localhost:8080/mcp"
    }
  }
}
```

### Windsurf

1. Open Windsurf → **Settings** → **MCP**
2. Add a new MCP server:

```json
{
  "mcpServers": {
    "supabase": {
      "url": "http://localhost:8080/mcp"
    }
  }
}
```

### Testing Your IDE Connection

Once configured, ask your AI assistant:

> "What tables exist in my Supabase database? Use the Supabase MCP tools."

If the MCP server is properly connected, the AI will be able to query your database schema through the MCP tools.

---

## Troubleshooting

### MCP Returns 403 Forbidden

- The `ip-restriction` plugin is blocking your IP
- Run `docker inspect supabase-kong --format '{{range .NetworkSettings.Networks}}{{println .Gateway}}{{end}}'` to find your gateway IP
- Add it to `MCP_ALLOWED_IPS` in Coolify environment variables (format: `- 172.18.0.1`)
- Redeploy the Supabase service

### MCP Returns 404 Not Found

- The `/mcp` path may not be correctly routed
- Check Kong logs: `docker logs supabase-kong 2>&1 | grep mcp`
- Verify the `supabase-studio` container is healthy: `docker ps | grep studio`

### MCP Returns 502 Bad Gateway

- The `supabase-studio` container may not be running
- Check: `docker ps | grep supabase-studio`
- Verify Studio's health: `docker inspect supabase-studio --format='{{.State.Health.Status}}'`

### SSH Tunnel Disconnects

- Use `autossh` for persistent tunnels:
  ```bash
  autossh -M 0 -o "ServerAliveInterval 30" -o "ServerAliveCountMax 3" -L 8080:localhost:8000 your-user@your-coolify-server
  ```

### Multiple Instances Colliding

- Each instance MUST have a unique port mapping in Coolify
- Verify ports in Coolify UI → Service → supabase-kong → Ports
- Use the correct port per instance when accessing MCP

### WireGuard Can't Reach MCP

- Verify WireGuard client has the correct `AllowedIPs` covering the Docker network
- Check `iptables` on the Coolify server: `iptables -L -n | grep 10.8`
- Ensure the WireGuard server has IP forwarding enabled: `sysctl net.ipv4.ip_forward`

---

## Quick Reference

| Task | Command / Value |
|---|---|
| Find Docker gateway IP | `docker inspect supabase-kong --format '{{range .NetworkSettings.Networks}}{{println .Gateway}}{{end}}'` |
| Create SSH tunnel | `ssh -L 8080:localhost:8000 user@server` |
| MCP env var key | `MCP_ALLOWED_IPS` |
| MCP env var value format | `- 172.18.0.1` (YAML list item) |
| Test MCP endpoint | `curl http://localhost:8080/mcp -X POST -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1.0.0"}}}'` |
| Restart Kong | `docker compose restart kong` (in Supabase project dir) |
| Check Kong logs | `docker logs supabase-kong 2>&1 \| tail -50` |
