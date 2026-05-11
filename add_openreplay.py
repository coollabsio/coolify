import json, base64

# Read the compose file
with open('openreplay-compose.yaml', 'r') as f:
    compose_content = f.read()

# Base64 encode
compose_b64 = base64.b64encode(compose_content.encode()).decode()

# Read existing templates
with open('templates/service-templates.json', 'r') as f:
    templates = json.load(f)

# Add Open Replay template
templates['openreplay'] = {
    "documentation": "https://docs.openreplay.com/en/deployment/deploy-docker?utm_source=coolify.io",
    "slogan": "Open source session replay for developers.",
    "compose": compose_b64,
    "tags": ["session replay", "debugging", "analytics", "developer tools", "open source"],
    "category": "developer-tools",
    "logo": "svgs/openreplay.png",
    "minversion": "0.0.0",
    "port": "80"
}

# Write back
with open('templates/service-templates.json', 'w') as f:
    json.dump(templates, f, indent=2)

print(f"Added openreplay template. Total services: {len(templates)}")
print(f"Compose base64 length: {len(compose_b64)}")
