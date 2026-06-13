# Supabase MCP Deployment Guide for Coolify

This guide explains how to deploy and configure the Supabase Model Context Protocol (MCP) using Coolify's new declarative service system.

## 1. Deployment
1. Go to **Resources** > **New Service**.
2. Select the **Supabase MCP** template.
3. In the configuration view, you will see a new **Enable Supabase MCP** toggle.
4. Set your **MCP Auth Token** (or leave the generated one).
5. Click **Deploy**.

## 2. Database Initialization
Once the services are running:
1. Go to the service's **Operations** tab.
2. Find the **Scripts** section.
3. Click **Run** on the `Initialize MCP Schema` script. This will prepare your database for MCP interactions.

## 3. Wireguard VPN Setup (Optional but Recommended)
To securely connect your local AI client to the MCP worker:
1. In Coolify, go to **Servers** > **Your Server** > **Wireguard**.
2. Enable Wireguard and create a new client.
3. Install Wireguard on your local machine and use the generated config.
4. Your AI client can now reach the MCP worker at its internal container name or IP.

## 4. AI Client Configuration
If you are using an AI client (like Claude Desktop or a custom agent):
1. Configure it to use the MCP worker endpoint:
   - URL: `http://mcp-worker:3000` (within VPN) or your configured FQDN.
   - Headers: `Authorization: Bearer <YOUR_MCP_AUTH_TOKEN>`

## 5. Troubleshooting
- If the MCP worker fails to connect to the database, ensure the `SERVICE_PASSWORD_POSTGRES` is correctly set in the environment variables.
- Check the logs in the **Logs** tab of the `mcp-worker` service.
