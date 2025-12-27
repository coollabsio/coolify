# Getting Supabase MCP Working on Coolify

So you want to use Supabase's MCP (Model Context Protocol) with your AI coding tools? Great choice! This guide will walk you through everything step by step.

> **Quick heads up on security**: MCP doesn't have proper authentication built in yet (no OAuth 2.1), so please don't expose it to the public internet. We'll set it up behind a VPN or SSH tunnel to keep things safe.

## What You'll Need

Before we dive in, make sure you have:
- Coolify v4.0.0-beta.228 or newer running
- A Supabase service already deployed from Coolify's template
- SSH access to your server (you probably already have this!)

## Quick Start: SSH Tunnel Method

If you just want to get MCP working quickly for local development, this is the easiest way.

Open your terminal and run:

```bash
ssh -L 3000:localhost:3000 user@your-coolify-server
```

That's it! Now you can access MCP at `http://localhost:3000`. Keep that terminal open while you're using it.

Want to make sure it's actually working? SSH into your server and check:

```bash
ssh user@your-coolify-server
docker ps | grep supabase-mcp
docker logs <container-id>
```

## The Better Way: Setting Up Wireguard VPN

For a more permanent setup (especially if you're working with a team), let's set up Wireguard. It's easier than it sounds!

### Step 1: Get Wireguard Running on Coolify

Head over to your Coolify dashboard:
1. Click on **Projects** → **New Service**
2. Search for **wireguard-easy** and hit deploy
3. Fill in these settings:

```
WG_HOST=vpn.yourdomain.com
PASSWORD=pick-something-secure-here
WG_DEFAULT_ADDRESS=10.0.0.x
WG_ALLOWED_IPS=10.0.0.0/24
```

### Step 2: Tell Supabase About Your MCP Domain

Go to your Supabase service settings and add these environment variables:

```env
MCP_DOMAIN=mcp-myproject.yourdomain.com
MCP_ALLOWED_IPS=10.0.0.0/24
```

Pick a subdomain that makes sense for your project. The `MCP_ALLOWED_IPS` tells Traefik to only allow connections from your VPN network.

### Step 3: Set Up Your DNS

Create an A record pointing your MCP domain to your Coolify server:

`mcp-myproject.yourdomain.com` → `your.server.ip.address`

### Step 4: Connect and Test

1. Grab your Wireguard config from the wireguard-easy admin panel
2. Import it into the Wireguard app on your computer/phone
3. Connect to the VPN
4. Visit `https://mcp-myproject.yourdomain.com` - you should see it working!

## Setting Up Your Code Editor

Now for the fun part - let's connect your AI coding assistant to Supabase!

### For Cursor Users

Open Cursor Settings → Features → Model Context Protocol, then add a new server:

**Using Wireguard VPN:**
```
Name: Supabase
URL: https://mcp-myproject.yourdomain.com
```

**Using SSH Tunnel:**
```
Name: Supabase
URL: http://localhost:3000
```

Or manually edit `~/.cursor/mcp_settings.json`:

```json
{
  "mcpServers": {
    "supabase": {
      "url": "https://mcp-myproject.yourdomain.com"
    }
  }
}
```

### For Claude Desktop Users

Find your config file:
- **Mac**: `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`  
- **Linux**: `~/.config/Claude/claude_desktop_config.json`

Add this to it:

```json
{
  "mcpServers": {
    "supabase": {
      "command": "npx",
      "args": ["@supabase/postgres-mcp"],
      "env": {
        "DATABASE_URL": "postgresql://postgres:your-password@localhost:5432/postgres"
      }
    }
  }
}
```

> **Note**: Claude Desktop typically runs MCP servers locally via command. For remote access through your VPN, use the SSH tunnel method and connect to `localhost:5432` with your Supabase credentials.

### For Windsurf Users

Create `.windsurf/mcp_settings.json` in your home directory:

```json
{
  "mcpServers": {
    "supabase": {
      "url": "https://mcp-myproject.yourdomain.com"
    }
  }
}
```

Or using SSH tunnel:

```json
{
  "mcpServers": {
    "supabase": {
      "url": "http://localhost:3000"
    }
  }
}
```

## Running Multiple Supabase Projects? No Problem!

Here's the cool part - if you're running several Supabase instances on the same Coolify server (maybe one for your blog, one for your shop, etc.), we've got you covered.

### Why This Usually Breaks

Normally, running multiple instances causes headaches:
- Router names clash with each other
- Middleware gets confused
- Ports step on each other's toes

### How We Fixed It

Each Coolify service gets a unique ID called `SERVICE_ID`. We use this to make sure everything stays separate:

```yaml
traefik.http.routers.supabase-mcp-${SERVICE_ID}      # Unique router
traefik.http.middlewares.mcp-vpn-only-${SERVICE_ID}  # Unique middleware  
traefik.http.services.supabase-mcp-${SERVICE_ID}     # Unique service
```

### Setting It Up

Just give each project its own MCP domain:

**Your blog project:**
```env
MCP_DOMAIN=mcp-blog.yourdomain.com
```

**Your e-commerce project:**
```env
MCP_DOMAIN=mcp-shop.yourdomain.com
```

**Your analytics project:**
```env
MCP_DOMAIN=mcp-analytics.yourdomain.com
```

Then in your editor config, list them all:

```json
{
  "mcpServers": {
    "supabase-blog": {
      "url": "https://mcp-blog.yourdomain.com"
    },
    "supabase-shop": {
      "url": "https://mcp-shop.yourdomain.com"
    },
    "supabase-analytics": {
      "url": "https://mcp-analytics.yourdomain.com"
    }
  }
}
```

Now your AI assistant can talk to whichever database you need!

## Something Not Working?

### Can't reach MCP at all?

Check if the container is actually running:

```bash
docker ps | grep supabase-mcp
docker logs <container-id>
```

### Getting a 403 Forbidden error?

This means Traefik is blocking you because you're not on the VPN. Double-check:
1. Is Wireguard actually connected?
2. What's your current IP? (It should start with `10.0.0.`)
3. Maybe your IP range is different - try adjusting `MCP_ALLOWED_IPS`

### Connection refused?

1. Is the MCP container running?
2. Does your DNS actually point to the right server?
3. Is port 443 open in your firewall?

## Quick Reference

| Setting | Default | What it does |
|---------|---------|--------------|
| `MCP_DOMAIN` | `mcp.localhost` | The subdomain for your MCP endpoint |
| `MCP_ALLOWED_IPS` | `10.0.0.0/24` | Which IPs can access MCP (your VPN range) |

## Want to Learn More?

- [Official Supabase MCP Docs](https://supabase.com/docs/guides/self-hosting/enable-mcp)
- [Coolify Documentation](https://coolify.io/docs)
- [The GitHub issue that started this](https://github.com/coollabsio/coolify/issues/7458)

---

*Hope this helps! If you run into any issues, feel free to open a discussion on the GitHub repo.*
