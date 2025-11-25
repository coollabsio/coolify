# Hetzner Server Provisioning API Examples

This document contains ready-to-use curl examples for the Hetzner server provisioning API endpoints. These examples use the `root` API token for development and can be easily imported into Yaak or any other API client.

## Prerequisites

```bash
# Set these environment variables
export COOLIFY_URL="http://localhost"
export API_TOKEN="root"  # Your Coolify API token
```

## Cloud Provider Tokens

### 1. Create a Hetzner Cloud Provider Token

```bash
curl -X POST "${COOLIFY_URL}/api/v1/cloud-tokens" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "provider": "hetzner",
    "token": "YOUR_HETZNER_API_TOKEN_HERE",
    "name": "My Hetzner Token"
  }'
```

**Response:**
```json
{
  "uuid": "abc123def456"
}
```

### 2. List All Cloud Provider Tokens

```bash
curl -X GET "${COOLIFY_URL}/api/v1/cloud-tokens" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json"
```

**Response:**
```json
[
  {
    "uuid": "abc123def456",
    "name": "My Hetzner Token",
    "provider": "hetzner",
    "team_id": 0,
    "servers_count": 0,
    "created_at": "2025-11-19T12:00:00.000000Z",
    "updated_at": "2025-11-19T12:00:00.000000Z"
  }
]
```

### 3. Get a Specific Cloud Provider Token

```bash
curl -X GET "${COOLIFY_URL}/api/v1/cloud-tokens/abc123def456" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json"
```

### 4. Update Cloud Provider Token Name

```bash
curl -X PATCH "${COOLIFY_URL}/api/v1/cloud-tokens/abc123def456" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Production Hetzner Token"
  }'
```

### 5. Validate a Cloud Provider Token

```bash
curl -X POST "${COOLIFY_URL}/api/v1/cloud-tokens/abc123def456/validate" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json"
```

**Response:**
```json
{
  "valid": true,
  "message": "Token is valid."
}
```

### 6. Delete a Cloud Provider Token

```bash
curl -X DELETE "${COOLIFY_URL}/api/v1/cloud-tokens/abc123def456" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json"
```

**Response:**
```json
{
  "message": "Cloud provider token deleted."
}
```

## Hetzner Resource Discovery

### 7. Get Available Hetzner Locations

```bash
curl -X GET "${COOLIFY_URL}/api/v1/hetzner/locations?cloud_provider_token_id=abc123def456" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json"
```

**Response:**
```json
[
  {
    "id": 1,
    "name": "fsn1",
    "description": "Falkenstein DC Park 1",
    "country": "DE",
    "city": "Falkenstein",
    "latitude": 50.47612,
    "longitude": 12.370071
  },
  {
    "id": 2,
    "name": "nbg1",
    "description": "Nuremberg DC Park 1",
    "country": "DE",
    "city": "Nuremberg",
    "latitude": 49.452102,
    "longitude": 11.076665
  },
  {
    "id": 3,
    "name": "hel1",
    "description": "Helsinki DC Park 1",
    "country": "FI",
    "city": "Helsinki",
    "latitude": 60.169857,
    "longitude": 24.938379
  }
]
```

### 8. Get Available Hetzner Server Types

```bash
curl -X GET "${COOLIFY_URL}/api/v1/hetzner/server-types?cloud_provider_token_id=abc123def456" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json"
```

**Response (truncated):**
```json
[
  {
    "id": 1,
    "name": "cx11",
    "description": "CX11",
    "cores": 1,
    "memory": 2.0,
    "disk": 20,
    "prices": [
      {
        "location": "fsn1",
        "price_hourly": {
          "net": "0.0052000000",
          "gross": "0.0061880000"
        },
        "price_monthly": {
          "net": "3.2900000000",
          "gross": "3.9151000000"
        }
      }
    ],
    "storage_type": "local",
    "cpu_type": "shared",
    "architecture": "x86",
    "deprecated": false
  }
]
```

### 9. Get Available Hetzner Images (Operating Systems)

```bash
curl -X GET "${COOLIFY_URL}/api/v1/hetzner/images?cloud_provider_token_id=abc123def456" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json"
```

**Response (truncated):**
```json
[
  {
    "id": 15512617,
    "name": "ubuntu-20.04",
    "description": "Ubuntu 20.04",
    "type": "system",
    "os_flavor": "ubuntu",
    "os_version": "20.04",
    "architecture": "x86",
    "deprecated": false
  },
  {
    "id": 67794396,
    "name": "ubuntu-22.04",
    "description": "Ubuntu 22.04",
    "type": "system",
    "os_flavor": "ubuntu",
    "os_version": "22.04",
    "architecture": "x86",
    "deprecated": false
  }
]
```

### 10. Get Hetzner SSH Keys

```bash
curl -X GET "${COOLIFY_URL}/api/v1/hetzner/ssh-keys?cloud_provider_token_id=abc123def456" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json"
```

**Response:**
```json
[
  {
    "id": 123456,
    "name": "my-ssh-key",
    "fingerprint": "aa:bb:cc:dd:ee:ff:11:22:33:44:55:66:77:88:99:00",
    "public_key": "ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQDe..."
  }
]
```

## Hetzner Server Provisioning

### 11. Create a Hetzner Server (Minimal Example)

First, you need to get your private key UUID:

```bash
curl -X GET "${COOLIFY_URL}/api/v1/security/keys" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json"
```

Then create the server:

```bash
curl -X POST "${COOLIFY_URL}/api/v1/servers/hetzner" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "cloud_provider_token_id": "abc123def456",
    "location": "nbg1",
    "server_type": "cx11",
    "image": 67794396,
    "private_key_uuid": "your-private-key-uuid"
  }'
```

**Response:**
```json
{
  "uuid": "server-uuid-123",
  "hetzner_server_id": 12345678,
  "ip": "1.2.3.4"
}
```

### 12. Create a Hetzner Server (Full Example with All Options)

```bash
curl -X POST "${COOLIFY_URL}/api/v1/servers/hetzner" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "cloud_provider_token_id": "abc123def456",
    "location": "nbg1",
    "server_type": "cx11",
    "image": 67794396,
    "name": "production-server",
    "private_key_uuid": "your-private-key-uuid",
    "enable_ipv4": true,
    "enable_ipv6": true,
    "hetzner_ssh_key_ids": [123456, 789012],
    "cloud_init_script": "#cloud-config\npackages:\n  - docker.io\n  - git",
    "instant_validate": true
  }'
```

**Parameters:**
- `cloud_provider_token_id` (required): UUID of your Hetzner cloud provider token
- `location` (required): Hetzner location name (e.g., "nbg1", "fsn1", "hel1")
- `server_type` (required): Hetzner server type (e.g., "cx11", "cx21", "ccx13")
- `image` (required): Hetzner image ID (get from images endpoint)
- `name` (optional): Server name (auto-generated if not provided)
- `private_key_uuid` (required): UUID of the private key to use for SSH
- `enable_ipv4` (optional): Enable IPv4 (default: true)
- `enable_ipv6` (optional): Enable IPv6 (default: true)
- `hetzner_ssh_key_ids` (optional): Array of additional Hetzner SSH key IDs
- `cloud_init_script` (optional): Cloud-init YAML script for initial setup
- `instant_validate` (optional): Validate server connection immediately (default: false)

## Complete Workflow Example

Here's a complete example of creating a Hetzner server from start to finish:

```bash
#!/bin/bash

# Configuration
export COOLIFY_URL="http://localhost"
export API_TOKEN="root"
export HETZNER_API_TOKEN="your-hetzner-api-token"

# Step 1: Create cloud provider token
echo "Creating cloud provider token..."
TOKEN_RESPONSE=$(curl -s -X POST "${COOLIFY_URL}/api/v1/cloud-tokens" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json" \
  -d "{
    \"provider\": \"hetzner\",
    \"token\": \"${HETZNER_API_TOKEN}\",
    \"name\": \"My Hetzner Token\"
  }")

CLOUD_TOKEN_ID=$(echo $TOKEN_RESPONSE | jq -r '.uuid')
echo "Cloud token created: $CLOUD_TOKEN_ID"

# Step 2: Get available locations
echo "Fetching locations..."
curl -s -X GET "${COOLIFY_URL}/api/v1/hetzner/locations?cloud_provider_token_id=${CLOUD_TOKEN_ID}" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json" | jq '.[] | {name, description, country}'

# Step 3: Get available server types
echo "Fetching server types..."
curl -s -X GET "${COOLIFY_URL}/api/v1/hetzner/server-types?cloud_provider_token_id=${CLOUD_TOKEN_ID}" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json" | jq '.[] | {name, cores, memory, disk}'

# Step 4: Get available images
echo "Fetching images..."
curl -s -X GET "${COOLIFY_URL}/api/v1/hetzner/images?cloud_provider_token_id=${CLOUD_TOKEN_ID}" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json" | jq '.[] | {id, name, description}'

# Step 5: Get private keys
echo "Fetching private keys..."
KEYS_RESPONSE=$(curl -s -X GET "${COOLIFY_URL}/api/v1/security/keys" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json")

PRIVATE_KEY_UUID=$(echo $KEYS_RESPONSE | jq -r '.[0].uuid')
echo "Using private key: $PRIVATE_KEY_UUID"

# Step 6: Create the server
echo "Creating server..."
SERVER_RESPONSE=$(curl -s -X POST "${COOLIFY_URL}/api/v1/servers/hetzner" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json" \
  -d "{
    \"cloud_provider_token_id\": \"${CLOUD_TOKEN_ID}\",
    \"location\": \"nbg1\",
    \"server_type\": \"cx11\",
    \"image\": 67794396,
    \"name\": \"my-production-server\",
    \"private_key_uuid\": \"${PRIVATE_KEY_UUID}\",
    \"enable_ipv4\": true,
    \"enable_ipv6\": false,
    \"instant_validate\": true
  }")

echo "Server created:"
echo $SERVER_RESPONSE | jq '.'

SERVER_UUID=$(echo $SERVER_RESPONSE | jq -r '.uuid')
SERVER_IP=$(echo $SERVER_RESPONSE | jq -r '.ip')

echo "Server UUID: $SERVER_UUID"
echo "Server IP: $SERVER_IP"
echo "You can now SSH to: root@$SERVER_IP"
```

## Error Handling

### Common Errors

**401 Unauthorized:**
```json
{
  "message": "Unauthenticated."
}
```
Solution: Check your API token.

**404 Not Found:**
```json
{
  "message": "Cloud provider token not found."
}
```
Solution: Verify the UUID exists and belongs to your team.

**422 Validation Error:**
```json
{
  "message": "Validation failed.",
  "errors": {
    "provider": ["The provider field is required."],
    "token": ["The token field is required."]
  }
}
```
Solution: Check the request body for missing or invalid fields.

**400 Bad Request:**
```json
{
  "message": "Invalid Hetzner token. Please check your API token."
}
```
Solution: Verify your Hetzner API token is correct.

## Testing with Yaak

To import these examples into Yaak:

1. Copy any curl command from this document
2. In Yaak, click "Import" → "From cURL"
3. Paste the curl command
4. Update the environment variables (COOLIFY_URL, API_TOKEN) in Yaak's environment settings

Or create a Yaak environment with these variables:
```json
{
  "COOLIFY_URL": "http://localhost",
  "API_TOKEN": "root"
}
```

Then you can use `{{COOLIFY_URL}}` and `{{API_TOKEN}}` in your requests.

## Rate Limiting

The Hetzner API has rate limits. If you receive a 429 error, the HetznerService will automatically retry with exponential backoff. The API token validation endpoints are also rate-limited on the Coolify side.

## Security Notes

- **Never commit your Hetzner API token** to version control
- Store API tokens securely in environment variables or secrets management
- Use the validation endpoint to test tokens before creating resources
- Cloud provider tokens are encrypted at rest in the database
- The actual token value is never returned by the API (only the UUID)
