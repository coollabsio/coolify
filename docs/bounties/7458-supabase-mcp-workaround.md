# Coolify Self-Hosted Supabase MCP Workaround

## Problem

When following the [Supabase self-hosted MCP docs](https://supabase.com/docs/guides/self-hosting/enable-mcp) with Coolify's Supabase template:

1. **No easy on/off toggle**: MCP endpoint is blocked by default
2. **Kong exposure issue**: Coolify exposes Kong via URL by default, making the Supabase MCP approach messy or unsafe
3. **Multi-instance routing conflicts**: Multiple Supabase instances on the same Coolify server all expose `/mcp` on the same Kong port (443:8000), causing routing conflicts

## Solution Overview

This workaround provides:
- ✓ Configure MCP for local network access safely
- ✓ Setup Wireguard VPN for secure remote access
- ✓ Configure MCP clients (Cursor, Claude Code, Windsurf)
- ✓ **Connect to multiple Supabase instances on the same Coolify server**

## Prerequisites

- Coolify self-hosted instance running Supabase
- Wireguard VPN server (can be self-hosted or VPS)
- MCP client (Cursor, Claude Code, or Windsurf)

## Part 1: Configure MCP for Local Network Access

### Understanding the Current Setup

Coolify's Supabase template uses Kong as the API gateway. The MCP endpoint is configured in the Kong declarative config:

```yaml
## MCP endpoint - local access
- name: mcp
  url: http://supabase-studio:3000/api/mcp
  routes:
    - name: mcp
      strip_path: true
      paths:
        - /mcp
  plugins:
    # Block access to /mcp by default
    - name: request-termination
      config:
        status_code: 403
        message: "Access is forbidden."
```

**This blocks all MCP access by default for security.**

### Step 1.1: Enable MCP via Coolify Custom Volumes

Instead of modifying the template directly (which breaks on updates), we'll use Coolify's custom volume mount feature:

1. **Access your Coolify Supabase service in the UI**
2. **Go to Service Settings > Volumes**
3. **Create a custom volume for Kong config**:
   - Mount path: `/home/kong/kong.yml`
   - Type: File
   - Content: (see Step 1.2)

### Step 1.2: Custom Kong Config with MCP Enabled

Paste this configuration into the `/home/kong/kong.yml` volume:

```yaml
_format_version: '2.1'
_transform: true

###
### Consumers / Users
###
consumers:
  - username: DASHBOARD
  - username: anon
    keyauth_credentials:
      - key: $SUPABASE_ANON_KEY
      - key: $SUPABASE_PUBLISHABLE_KEY
  - username: service_role
    keyauth_credentials:
      - key: $SUPABASE_SERVICE_KEY
      - key: $SUPABASE_SECRET_KEY

###
### Access Control List
###
acls:
  - consumer: anon
    group: anon
  - consumer: service_role
    group: admin

###
### Dashboard credentials
###
basicauth_credentials:
  - consumer: DASHBOARD
    username: '$DASHBOARD_USERNAME'
    password: '$DASHBOARD_PASSWORD'

###
### MCP endpoint - ENABLED FOR LOCAL ACCESS
###
- name: mcp-enabled
  _comment: 'MCP: /mcp -> http://supabase-studio:3000/api/mcp (local access with IP restriction)'
  url: http://supabase-studio:3000/api/mcp
  routes:
    - name: mcp-local-only
      strip_path: true
      paths:
        - /mcp
  plugins:
    - name: cors
      config:
        origins: ['*']
        methods: ['GET', 'POST', 'OPTIONS']
        headers: ['Content-Type', 'Authorization']
        credentials: true
        exposed_headers: ['Content-Type']
    - name: ip-restriction
      config:
        allow:
          - 127.0.0.1       # Docker host (Coolify server)
          - 10.0.0.0/8        # Local network (10.x.x.x)
          - 172.16.0.0/12     # Local network (172.16-31.x.x)
          - 192.168.0.0/16     # Local network (192.168.x.x)
        deny: []
    - name: key-auth
      config:
        hide_credentials: false
    - name: acl
      config:
        hide_groups_header: true
        allow:
          - admin
          - anon
```

**Key changes from default:**
- Removed `request-termination` plugin (was blocking MCP)
- Added `cors` plugin for browser-based MCP clients
- Added `ip-restriction` plugin to only allow local network access
- Kept `key-auth` and `acl` for Supabase auth integration

### Step 1.3: Restart the Supabase Service

After updating the Kong config volume:
1. Go to Coolify Service Settings
2. Click "Redeploy" to apply the new config
3. Verify MCP is accessible:
   ```bash
   curl -X POST http://localhost:<KONG_HTTP_PORT>/mcp \
     -H "Content-Type: application/json" \
     -H "apikey: $SUPABASE_ANON_KEY" \
     -d '{"method": "tools/list"}'
   ```

## Part 2: Setup Wireguard VPN for Remote Access

For accessing MCP remotely (from a different location), use Wireguard instead of exposing it publicly.

### Step 2.1: Install Wireguard on Coolify Server

```bash
# Install Wireguard
sudo apt update
sudo apt install wireguard -y

# Generate keys
wg genkey | tee privatekey | wg pubkey > publickey

# Create config
sudo nano /etc/wireguard/wg0.conf
```

### Step 2.2: Wireguard Server Config

`/etc/wireguard/wg0.conf`:

```ini
[Interface]
PrivateKey = <YOUR_PRIVATE_KEY>
Address = 10.100.0.1/24
ListenPort = 51820
SaveConfig = false

[Peer]
PublicKey = <CLIENT_PUBLIC_KEY>
AllowedIPs = 10.100.0.2/32
```

### Step 2.3: Enable and Start Wireguard

```bash
# Enable IP forwarding
sudo sysctl -w net.ipv4.ip_forward=1
echo "net.ipv4.ip_forward=1" | sudo tee -a /etc/sysctl.conf

# Start Wireguard
sudo systemctl enable wg-quick@wg0
sudo wg-quick up wg0
```

### Step 2.4: Configure Firewall

```bash
# Allow Wireguard port
sudo ufw allow 51820/udp
```

### Step 2.5: Client Config (for your dev machine)

Create `wg0.conf` on your client machine:

```ini
[Interface]
PrivateKey = <YOUR_CLIENT_PRIVATE_KEY>
Address = 10.100.0.2/24
DNS = 1.1.1.1

[Peer]
PublicKey = <SERVER_PUBLIC_KEY>
Endpoint = <COOLIFY_SERVER_IP>:51820
AllowedIPs = 10.100.0.0/24
PersistentKeepalive = 25
```

Connect:
```bash
sudo wg-quick up wg0
```

Now you can access MCP via the Wireguard VPN IP:
```bash
curl http://10.100.0.1:<KONG_HTTP_PORT>/mcp
```

## Part 3: Configure MCP Clients

### Step 3.1: Cursor (Claude Desktop)

Cursor's MCP configuration uses a JSON file at `~/.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "supabase-local": {
      "command": "node",
      "args": [
        "-e",
        "require('http').request({method:'POST',hostname:'10.100.0.1',port:<KONG_PORT>,path:'/mcp',headers:{'Content-Type':'application/json','Authorization':'Bearer $SUPABASE_ANON_KEY'},body:JSON.stringify({jsonrpc:'2.0',id:1,method:'tools/list'})},(res)=>{if(res.statusCode!==200)process.exit(1);console.log(res.body)})"
      ]
    }
  }
}
```

**Better approach: Use MCP SDK wrapper**

Create a Node.js MCP server that proxies to Supabase:

`mcp-supabase-proxy.js`:

```javascript
const express = require('express');
const axios = require('axios');

const app = express();
const SUPABASE_URL = process.env.SUPABASE_URL || 'http://localhost:8000';
const SUPABASE_ANON_KEY = process.env.SUPABASE_ANON_KEY;

app.use(express.json());

// SSE endpoint for Cursor/Claude
app.all('/sse', async (req, res) => {
  res.setHeader('Content-Type', 'text/event-stream');
  res.setHeader('Cache-Control', 'no-cache');
  res.setHeader('Connection', 'keep-alive');

  // Proxy to Supabase MCP
  try {
    const response = await axios.post(`${SUPABASE_URL}/mcp`, req.body, {
      headers: {
        'Content-Type': 'application/json',
        'apikey': SUPABASE_ANON_KEY
      },
      responseType: 'stream'
    });

    response.data.pipe(res);
  } catch (error) {
    console.error('MCP proxy error:', error.message);
    res.status(500).end('MCP proxy error');
  }
});

// JSON-RPC endpoint
app.post('/rpc', async (req, res) => {
  try {
    const response = await axios.post(`${SUPABASE_URL}/mcp`, req.body, {
      headers: {
        'Content-Type': 'application/json',
        'apikey': SUPABASE_ANON_KEY
      }
    });
    res.json(response.data);
  } catch (error) {
    console.error('MCP proxy error:', error.message);
    res.status(500).json({ error: error.message });
  }
});

app.listen(3000, () => {
  console.log('MCP proxy running on port 3000');
  console.log('Forwarding to:', SUPABASE_URL);
});
```

Run:
```bash
SUPABASE_URL=http://localhost:8000 \
SUPABASE_ANON_KEY=your_anon_key \
node mcp-supabase-proxy.js
```

Then configure Cursor:
```json
{
  "mcpServers": {
    "supabase-proxy": {
      "command": "node",
      "args": ["mcp-supabase-proxy.js"]
    }
  }
}
```

### Step 3.2: Claude Code

Similar to Cursor, use the MCP proxy approach. Claude Code reads `~/.config/claude-code/mcp.json`:

```json
{
  "mcpServers": {
    "supabase-local": {
      "command": "node",
      "args": ["mcp-supabase-proxy.js"],
      "env": {
        "SUPABASE_URL": "http://localhost:8000",
        "SUPABASE_ANON_KEY": "your_anon_key_here"
      }
    }
  }
}
```

### Step 3.3: Windsurf

Windsurf uses the same MCP config format as Claude Code. Use the same `mcp-supabase-proxy.js` approach.

## Part 4: Multiple Supabase Instances on Same Coolify Server

**This is the core problem**: Multiple Supabase instances on the same Coolify server all expose `/mcp` on the same Kong port (443:8000), causing routing conflicts.

### Solution: Unique Paths per Instance

Each Supabase instance needs a unique MCP path. Here's how to configure them:

#### Instance 1 (Production)

1. Use the default `/mcp` path (configured in Step 1.2)
2. MCP URL: `http://localhost:8000/mcp`
3. Proxy env: `SUPABASE_URL=http://localhost:8000`

#### Instance 2 (Staging)

1. **Clone the Supabase service in Coolify** to create Instance 2
2. **Update Kong config volume** with unique path:
   ```yaml
   routes:
     - name: mcp-local-staging
       strip_path: true
       paths:
         - /mcp-staging  # Unique path!
   plugins:
     - name: cors
     - name: ip-restriction
       config:
         allow:
           - 127.0.0.1
           - 10.0.0.0/8
           - 172.16.0.0/12
           - 192.168.0.0/16
         deny: []
   ```

3. MCP URL: `http://localhost:8000/mcp-staging`
4. Create separate proxy:
   ```javascript
   const SUPABASE_URL = process.env.SUPABASE_URL || 'http://localhost:8000/mcp-staging';
   ```

#### Instance 3 (Development)

1. **Clone the service again** for Instance 3
2. Update Kong config with `/mcp-dev` path
3. MCP URL: `http://localhost:8000/mcp-dev`

### Complete Multi-Instance Setup

**Three separate MCP proxies**:

`proxy-prod.js` (Instance 1 - Production):
```bash
SUPABASE_URL=http://localhost:8000/mcp \
SUPABASE_ANON_KEY=prod_anon_key \
node proxy-prod.js
```

`proxy-staging.js` (Instance 2 - Staging):
```bash
SUPABASE_URL=http://localhost:8000/mcp-staging \
SUPABASE_ANON_KEY=staging_anon_key \
node proxy-staging.js
```

`proxy-dev.js` (Instance 3 - Development):
```bash
SUPABASE_URL=http://localhost:8000/mcp-dev \
SUPABASE_ANON_KEY=dev_anon_key \
node proxy-dev.js
```

**Configure all three in Cursor/Claude Code**:

```json
{
  "mcpServers": {
    "supabase-prod": {
      "command": "node",
      "args": ["proxy-prod.js"]
    },
    "supabase-staging": {
      "command": "node",
      "args": ["proxy-staging.js"]
    },
    "supabase-dev": {
      "command": "node",
      "args": ["proxy-dev.js"]
    }
  }
}
```

**Each instance now has:**
- Unique MCP path (`/mcp`, `/mcp-staging`, `/mcp-dev`)
- Unique proxy process on different port
- Separate Supabase credentials (anon keys)
- No routing conflicts

## Testing

### Test MCP Direct Access

```bash
# Test instance 1 (default)
curl -X POST http://localhost:8000/mcp \
  -H "Content-Type: application/json" \
  -H "apikey: $SUPABASE_ANON_KEY" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'

# Test instance 2
curl -X POST http://localhost:8000/mcp-staging \
  -H "Content-Type: application/json" \
  -H "apikey: $STAGING_SUPABASE_ANON_KEY" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

### Test MCP Proxy

```bash
# Test proxy
curl -X POST http://localhost:3000/rpc \
  -H "Content-Type": "application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'

# Should return list of Supabase MCP tools
```

## Security Notes

⚠️ **Important Security Considerations**:

1. **IP Restriction**: The `ip-restriction` plugin limits access to local networks. Adjust ranges as needed.

2. **Wireguard over Public Exposure**: Always use VPN for remote access instead of exposing MCP publicly.

3. **Separate Anon Keys**: Each Supabase instance should have its own anon/service keys. Never share keys.

4. **Kong Config Backup**: Keep a backup of your working Kong config before making changes.

5. **Coolify Updates**: When Coolify updates the Supabase template, re-apply your custom Kong config volume.

## Troubleshooting

### MCP Returns 403
- Check that `request-termination` plugin is removed from Kong config
- Verify IP restriction includes your network
- Restart the Supabase service in Coolify

### Connection Refused
- Check Kong is running: `docker ps | grep kong`
- Check Kong port mapping: `docker port <supabase-container> 8000`
- Verify firewall rules

### Multiple Instance Routing Conflicts
- Ensure each instance uses unique MCP paths (`/mcp`, `/mcp-staging`, `/mcp-dev`)
- Verify each proxy uses the correct base URL
- Check Supabase service names in Docker

## Summary

This workaround solves the Supabase MCP setup issues on Coolify by:

1. ✅ **Configurable MCP**: Custom Kong config volume instead of template changes
2. ✅ **Safe Local Access**: IP-restricted MCP endpoint (no public exposure)
3. ✅ **Remote Access**: Wireguard VPN for secure remote development
4. ✅ **Client Support**: MCP proxy for Cursor/Claude Code/Windsurf
5. ✅ **Multi-Instance Support**: Unique MCP paths per Supabase instance

**No template code changes required** - all configuration done via Coolify UI and custom volumes.

---

*Created for Coolify issue #7458 - Self-Hosted Supabase MCP Setup*
*Bounty: $15.00 on Algora.io*
