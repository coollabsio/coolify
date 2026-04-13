# Supabase MCP Proxy

This proxy server enables MCP clients (Cursor, Claude Code, Windsurf) to connect to Supabase's MCP endpoint through a local Express server.

## Installation

```bash
cd mcp-proxies
npm install
```

## Usage

### Single Instance

```bash
export SUPABASE_URL=http://localhost:8000/mcp
export SUPABASE_ANON_KEY=your_anon_key_here
npm start
```

### Multiple Instances

Run each instance on a different port:

```bash
# Production (port 3000)
export SUPABASE_URL=http://localhost:8000/mcp
export SUPABASE_ANON_KEY=prod_anon_key
export PROXY_PORT=3000
npm start

# Staging (port 3001)
export SUPABASE_URL=http://localhost:8000/mcp-staging
export SUPABASE_ANON_KEY=staging_anon_key
export PROXY_PORT=3001
npm start

# Development (port 3002)
export SUPABASE_URL=http://localhost:8000/mcp-dev
export SUPABASE_ANON_KEY=dev_anon_key
export PROXY_PORT=3002
npm start
```

Or use the predefined scripts:

```bash
npm run start:prod      # Production instance
npm run start:staging   # Staging instance
npm run start:dev        # Development instance
```

## Configure MCP Clients

### Cursor (~/.cursor/mcp.json)

```json
{
  "mcpServers": {
    "supabase-prod": {
      "command": "node",
      "args": ["/path/to/mcp-supabase-proxy.js"],
      "cwd": "/path/to/mcp-proxies"
    },
    "supabase-staging": {
      "command": "node",
      "args": ["/path/to/mcp-supabase-proxy.js"],
      "cwd": "/path/to/mcp-proxies",
      "env": {
        "SUPABASE_URL": "http://localhost:8000/mcp-staging",
        "SUPABASE_ANON_KEY": "staging_anon_key",
        "PROXY_PORT": "3001"
      }
    },
    "supabase-dev": {
      "command": "node",
      "args": ["/path/to/mcp-supabase-proxy.js"],
      "cwd": "/path/to/mcp-proxies",
      "env": {
        "SUPABASE_URL": "http://localhost:8000/mcp-dev",
        "SUPABASE_ANON_KEY": "dev_anon_key",
        "PROXY_PORT": "3002"
      }
    }
  }
}
```

### Claude Code (~/.config/claude-code/mcp.json)

Same format as Cursor.

### Windsurf (~/.config/windsurf/mcp.json)

Same format as Cursor.

## Health Check

```bash
curl http://localhost:3000/health
```

## Troubleshooting

### Connection Refused
- Check Supabase instance is running
- Verify SUPABASE_URL is correct
- Check port is not already in use

### 403 Forbidden
- Verify MCP is enabled in Supabase Kong config
- Check IP restriction includes your network
- Restart Supabase service in Coolify

### CORS Errors
- Add CORS plugin to Kong MCP route
- Check Supabase anon key is correct
