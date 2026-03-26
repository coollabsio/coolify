# Supabase MCP (Model Context Protocol) Setup Guide

This guide explains how to configure and use the MCP service with your Supabase instance on Coolify.

## ⚠️ Important Security Notice

**MCP should NEVER be exposed to the internet.** It provides direct database access without OAuth 2.1 authentication. Only use these access methods:

- ✅ Local network (development only)
- ✅ WireGuard VPN
- ✅ SSH tunnel
- ❌ Direct internet exposure (dangerous!)

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                     Your Local Machine                       │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │   Cursor     │  │  Claude Code │  │   Windsurf   │      │
│  │    IDE       │  │     CLI      │  │     IDE      │      │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘      │
│         │                 │                 │               │
│         └─────────────────┼─────────────────┘               │
│                           │                                 │
│                    MCP Client Config                        │
│                  (mcpServers config)                        │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            │ HTTP/SSE
                            │
┌───────────────────────────▼─────────────────────────────────┐
│                     VPN / SSH Tunnel                         │
│                 (Secure Connection)                          │
└───────────────────────────┬─────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────┐
│                   Coolify Server                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │                    Traefik                           │   │
│  │              (Reverse Proxy)                         │   │
│  └──────────────────────┬──────────────────────────────┘   │
│                         │                                   │
│  ┌──────────────────────▼──────────────────────────────┐   │
│  │              Supabase Kong Gateway                   │   │
│  │           (API Routing & Auth)                       │   │
│  └──────────────────────┬──────────────────────────────┘   │
│                         │                                   │
│  ┌──────────────────────▼──────────────────────────────┐   │
│  │              Supabase MCP Service                    │   │
│  │         (postgres-mcp:3000)                          │   │
│  └──────────────────────┬──────────────────────────────┘   │
│                         │                                   │
│  ┌──────────────────────▼──────────────────────────────┐   │
│  │              PostgreSQL Database                     │   │
│  │            (supabase-db:5432)                        │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

## Access Methods

### Method 1: WireGuard VPN (Recommended for Production)

WireGuard provides secure, fast VPN access to your server.

#### Step 1: Install WireGuard on Server

```bash
# On your Coolify server
sudo apt install wireguard
```

#### Step 2: Generate Keys

```bash
# Server keys
wg genkey | tee server_private.key | wg pubkey > server_public.key

# Client keys (run on your local machine)
wg genkey | tee client_private.key | wg pubkey > client_public.key
```

#### Step 3: Configure Server

Create `/etc/wireguard/wg0.conf`:

```ini
[Interface]
Address = 10.0.0.1/24
ListenPort = 51820
PrivateKey = <server_private_key>

[Peer]
PublicKey = <client_public_key>
AllowedIPs = 10.0.0.2/32
```

#### Step 4: Configure Client

Create `/etc/wireguard/wg0.conf` on your local machine:

```ini
[Interface]
Address = 10.0.0.2/24
PrivateKey = <client_private_key>

[Peer]
PublicKey = <server_public_key>
Endpoint = <your-server-ip>:51820
AllowedIPs = 10.0.0.0/24
PersistentKeepalive = 25
```

#### Step 5: Start WireGuard

```bash
# On server
sudo wg-quick up wg0
sudo systemctl enable wg-quick@wg0

# On client
sudo wg-quick up wg0
```

### Method 2: SSH Tunnel (Quick Setup)

For temporary access, use SSH port forwarding:

```bash
# Forward MCP port (8000) to localhost
ssh -L 8000:localhost:8000 user@your-server-ip

# Keep terminal open while using MCP
```

### Method 3: Local Network (Development Only)

If your development machine is on the same network as the server:

```bash
# Use server's local IP directly
# WARNING: Only safe in trusted networks!
```

## IDE Configuration

### Cursor IDE

Add to `~/.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "supabase": {
      "url": "http://<server-ip>:8000/mcp/v1/sse",
      "headers": {
        "apikey": "<your-service-role-key>",
        "Authorization": "Bearer <your-service-role-key>"
      }
    }
  }
}
```

### Claude Code CLI

Add to `~/.claude/mcp.json`:

```json
{
  "mcpServers": {
    "supabase": {
      "type": "sse",
      "url": "http://<server-ip>:8000/mcp/v1/sse",
      "headers": {
        "apikey": "<your-service-role-key>",
        "Authorization": "Bearer <your-service-role-key>"
      }
    }
  }
}
```

### Windsurf IDE

Add to Windsurf settings:

```json
{
  "mcp.servers": {
    "supabase": {
      "url": "http://<server-ip>:8000/mcp/v1/sse",
      "headers": {
        "apikey": "<your-service-role-key>",
        "Authorization": "Bearer <your-service-role-key>"
      }
    }
  }
}
```

## Getting Your Service Role Key

1. Open Coolify Dashboard
2. Navigate to your Supabase service
3. Go to **Environment Variables**
4. Copy `SERVICE_SUPABASESERVICE_KEY`

**⚠️ Keep this key secret!** It provides full database access.

## Multiple Instances

If running multiple Supabase instances, each needs a unique `SERVICE_ID`:

### Environment Variables

```bash
# Instance 1
SERVICE_ID=project-alpha

# Instance 2
SERVICE_ID=project-beta
```

### MCP URLs

```
# Instance 1
http://<server-ip>:8000/mcp/v1/sse

# Instance 2 (different port or subdomain)
http://<server-ip>:8001/mcp/v1/sse
```

## Troubleshooting

### MCP Connection Refused

1. Check if MCP service is running:
   ```bash
   docker ps | grep mcp
   ```

2. Check Kong routing:
   ```bash
   curl -H "apikey: <service-role-key>" http://localhost:8000/mcp/v1/
   ```

3. Check Traefik routing:
   ```bash
   docker logs traefik 2>&1 | grep mcp
   ```

### Health Check Failing

MCP uses PostgreSQL health check:

```bash
# Test database connectivity
docker exec supabase-mcp pg_isready -h supabase-db -p 5432
```

### WireGuard Connection Issues

```bash
# Check WireGuard status
sudo wg show

# Check logs
sudo journalctl -u wg-quick@wg0 -f
```

## Resources

- [Supabase MCP Documentation](https://supabase.com/docs/guides/self-hosting/enable-mcp)
- [Coolify Documentation](https://coolify.io/docs)
- [WireGuard Documentation](https://www.wireguard.com/quickstart/)
- [MCP Specification](https://modelcontextprotocol.io/)

## Support

If you encounter issues:

1. Check the troubleshooting section above
2. Search [Coolify Discussions](https://github.com/coollabsio/coolify/discussions)
3. Open an issue with:
   - Your setup (VPN/SSH/Local)
   - Error messages
   - Docker logs: `docker logs supabase-mcp`
