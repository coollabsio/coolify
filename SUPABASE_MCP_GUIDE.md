# Supabase MCP: Deployment & Config Guide

Deploying an MCP (Model Context Protocol) worker can be a bit of a headache if the architecture isn't right. This guide walks you through setting up Supabase MCP using Coolify's new declarative system. I've designed this to be as "one-click" as possible, but there are a few things you'll want to get right on the client side.

## 1. Getting it Deployed
1. Head over to **Resources** > **New Service** and grab the **Supabase MCP** template.
2. You'll notice a new **Enable Supabase MCP** toggle—I've added this so you can decide if you actually want the worker running alongside your DB.
3. Set your **MCP Auth Token**. I've defaulted it to a random string, but feel free to drop in your own. Just make sure it's secure.
4. Hit **Deploy**.

## 2. Initializing the Database (Don't skip this!)
Once the services are green, you need to prep the DB. Instead of making you SSH in, I've added a maintenance script:
1. Go to the **Operations** tab for your service.
2. Look for the **Scripts** section.
3. Click **Run** on the `Initialize MCP Schema` script. This handles all the migrations so the worker can actually talk to your tables.

## 3. Secure Access via Wireguard
If you're like me and prefer not to expose your worker to the open internet, use the built-in Wireguard support:
1. Go to **Servers** > **Your Server** > **Wireguard**.
2. Set up a client for your local machine.
3. Once connected, your AI client (like Claude Desktop) can hit the worker directly using its internal container name or IP. It's much cleaner and way more secure.

## 4. Connecting your AI Client
When you're configuring your agent or Claude Desktop, use these settings:
- **Endpoint**: `http://mcp-worker:3000` (if you're on the VPN) or your FQDN.
- **Header**: `Authorization: Bearer <YOUR_MCP_AUTH_TOKEN>`

## 5. Troubleshooting
If things aren't connecting, check the logs for the `mcp-worker` service. Usually, it's just a mismatched Postgres password or a forgotten migration script. 

Happy shipping!
