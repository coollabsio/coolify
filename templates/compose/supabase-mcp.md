# Supabase MCP with Coolify

This is the supported workaround for using the self-hosted Supabase MCP endpoint with Coolify without exposing it publicly.

Supabase Studio serves the self-hosted MCP endpoint internally at `/api/mcp`. The Supabase guidance is to keep that route denied from the Internet and reach it only through a VPN or SSH tunnel. The Coolify Supabase template follows that model:

- `/api/mcp` is blocked directly by Kong.
- `/mcp` is routed to `supabase-studio:3000/api/mcp`.
- `/mcp` is also blocked until an operator explicitly enables an IP allowlist.

## Enable access for one Coolify Supabase service

1. Open the generated `volumes/api/kong.yml` for the Supabase service.
2. Keep the `mcp-blocker` route for `/api/mcp` enabled.
3. In the `mcp` service plugin list, comment out the `request-termination` plugin.
4. Uncomment the `cors` and `ip-restriction` plugins.
5. Add only trusted source IPs to `config.allow`.
6. Restart the `supabase-kong` service.

To use an SSH tunnel, first find the Docker bridge gateway IP that Kong sees:

```bash
docker inspect <kong-container> --format '{{range .NetworkSettings.Networks}}{{println .Gateway}}{{end}}'
```

Add that gateway IP to the `allow` list, then create a tunnel to the Coolify host:

```bash
ssh -L localhost:8080:localhost:8000 user@your-coolify-host
```

Configure the MCP client with the tunneled endpoint:

```json
{
  "mcpServers": {
    "supabase-self-hosted": {
      "url": "http://localhost:8080/mcp"
    }
  }
}
```

## Multiple Supabase services

Configure each Coolify Supabase service separately. Do not expose one shared public `/mcp` route for all instances.

- Use one tunnel, VPN route, or domain per Supabase service.
- Edit the `volumes/api/kong.yml` generated for that service only.
- Keep direct `/api/mcp` access blocked for every service.
- Add only the tunnel or VPN source IPs that must reach that specific instance.

## Troubleshooting

- A `403` from `/mcp` means the default block is still active or the source IP is not allowlisted.
- A `404` usually means the request is not reaching the Supabase Kong service or the path is not `/mcp`.
- If the client connects through SSH but is denied, allowlist the Docker bridge gateway IP rather than the public client IP.
- Restart `supabase-kong` after changing the generated Kong configuration.
