# Hetzner Server Provisioning via API

This implementation adds full API support for Hetzner server provisioning in Coolify, matching the functionality available in the UI.

## What's New

### API Endpoints

#### Cloud Provider Tokens
- `GET /api/v1/cloud-tokens` - List all cloud provider tokens
- `POST /api/v1/cloud-tokens` - Create a new cloud provider token (with validation)
- `GET /api/v1/cloud-tokens/{uuid}` - Get a specific token
- `PATCH /api/v1/cloud-tokens/{uuid}` - Update token name
- `DELETE /api/v1/cloud-tokens/{uuid}` - Delete token (prevents deletion if used by servers)
- `POST /api/v1/cloud-tokens/{uuid}/validate` - Validate token against provider API

#### Hetzner Resources
- `GET /api/v1/hetzner/locations` - List Hetzner datacenter locations
- `GET /api/v1/hetzner/server-types` - List server types (filters deprecated)
- `GET /api/v1/hetzner/images` - List OS images (filters deprecated & non-system)
- `GET /api/v1/hetzner/ssh-keys` - List SSH keys from Hetzner account

#### Hetzner Server Provisioning
- `POST /api/v1/servers/hetzner` - Create a new Hetzner server

## Files Added/Modified

### Controllers
- `app/Http/Controllers/Api/CloudProviderTokensController.php` - Cloud token CRUD operations
- `app/Http/Controllers/Api/HetznerController.php` - Hetzner provisioning operations

### Routes
- `routes/api.php` - Added new API routes

### Tests
- `tests/Feature/CloudProviderTokenApiTest.php` - Comprehensive tests for cloud tokens
- `tests/Feature/HetznerApiTest.php` - Comprehensive tests for Hetzner provisioning

### Documentation
- `docs/api/hetzner-provisioning-examples.md` - Complete curl examples
- `docs/api/hetzner-yaak-collection.json` - Importable Yaak/Postman collection
- `docs/api/HETZNER_API_README.md` - This file

## Features

### Authentication & Authorization
- All endpoints require Sanctum authentication
- Cloud token operations restricted to team members
- Follows existing API patterns for consistency

### Token Validation
- Tokens are validated against provider APIs before storage
- Supports both Hetzner and DigitalOcean
- Encrypted storage of API tokens

### Smart SSH Key Management
- Automatic MD5 fingerprint matching to avoid duplicate uploads
- Supports Coolify private key + additional Hetzner keys
- Automatic deduplication

### Network Configuration
- IPv4/IPv6 toggle support
- Prefers IPv4 when both enabled
- Validates at least one network type is enabled

### Cloud-init Support
- Optional cloud-init script for server initialization
- YAML validation using existing ValidCloudInitYaml rule

### Server Creation Flow
1. Validates cloud provider token and private key
2. Uploads SSH key to Hetzner if not already present
3. Creates server on Hetzner with all specified options
4. Registers server in Coolify database
5. Sets up default proxy configuration (Traefik)
6. Optional instant validation

## Testing

### Running Tests

**Feature Tests (require database):**
```bash
# Run inside Docker
docker exec coolify php artisan test --filter=CloudProviderTokenApiTest
docker exec coolify php artisan test --filter=HetznerApiTest
```

### Test Coverage

**CloudProviderTokenApiTest:**
- ✅ List all tokens (with team isolation)
- ✅ Get specific token
- ✅ Create Hetzner token (with API validation)
- ✅ Create DigitalOcean token (with API validation)
- ✅ Update token name
- ✅ Delete token (prevents if used by servers)
- ✅ Validate token
- ✅ Validation errors for all required fields
- ✅ Extra field rejection
- ✅ Authentication checks

**HetznerApiTest:**
- ✅ Get locations
- ✅ Get server types (filters deprecated)
- ✅ Get images (filters deprecated & non-system)
- ✅ Get SSH keys
- ✅ Create server (minimal & full options)
- ✅ IPv4/IPv6 preference logic
- ✅ Auto-generate server name
- ✅ Validation for all required fields
- ✅ Token & key existence validation
- ✅ Extra field rejection
- ✅ Authentication checks

## Usage Examples

### Quick Start

```bash
# 1. Create a cloud provider token
curl -X POST "http://localhost/api/v1/cloud-tokens" \
  -H "Authorization: Bearer root" \
  -H "Content-Type: application/json" \
  -d '{
    "provider": "hetzner",
    "token": "YOUR_HETZNER_API_TOKEN",
    "name": "My Hetzner Token"
  }'

# Save the returned UUID as CLOUD_TOKEN_UUID

# 2. Get available locations
curl -X GET "http://localhost/api/v1/hetzner/locations?cloud_provider_token_id=CLOUD_TOKEN_UUID" \
  -H "Authorization: Bearer root"

# 3. Get your private key UUID
curl -X GET "http://localhost/api/v1/security/keys" \
  -H "Authorization: Bearer root"

# Save the returned UUID as PRIVATE_KEY_UUID

# 4. Create a server
curl -X POST "http://localhost/api/v1/servers/hetzner" \
  -H "Authorization: Bearer root" \
  -H "Content-Type: application/json" \
  -d '{
    "cloud_provider_token_id": "CLOUD_TOKEN_UUID",
    "location": "nbg1",
    "server_type": "cx11",
    "image": 67794396,
    "private_key_uuid": "PRIVATE_KEY_UUID"
  }'
```

For complete examples, see:
- **[hetzner-provisioning-examples.md](./hetzner-provisioning-examples.md)** - Detailed curl examples
- **[hetzner-yaak-collection.json](./hetzner-yaak-collection.json)** - Import into Yaak

## API Design Decisions

### Consistency with Existing API
- Follows patterns from `ServersController`
- Uses same validation approach (inline with `customApiValidator`)
- Uses same response formatting (`serializeApiResponse`)
- Uses same error handling patterns

### Reuses Existing Code
- `HetznerService` - All Hetzner API calls
- `ValidHostname` rule - Server name validation
- `ValidCloudInitYaml` rule - Cloud-init validation
- `PrivateKey::generateMd5Fingerprint()` - SSH key fingerprinting

### Team Isolation
- All endpoints filter by team ID from API token
- Cannot access tokens/servers from other teams
- Follows existing security patterns

### Error Handling
- Provider API errors wrapped in generic messages (doesn't leak Hetzner errors)
- Validation errors with clear field-specific messages
- 404 for resources not found
- 422 for validation failures
- 400 for business logic errors (e.g., token validation failure)

## Next Steps

To use this in production:

1. **Run Pint** (code formatting):
   ```bash
   cd /Users/heyandras/devel/coolify
   ./vendor/bin/pint --dirty
   ```

2. **Run Tests** (inside Docker):
   ```bash
   docker exec coolify php artisan test --filter=CloudProviderTokenApiTest
   docker exec coolify php artisan test --filter=HetznerApiTest
   ```

3. **Commit Changes**:
   ```bash
   git add .
   git commit -m "feat: add Hetzner server provisioning API endpoints

   - Add CloudProviderTokensController for token CRUD operations
   - Add HetznerController for server provisioning
   - Add comprehensive feature tests
   - Add curl examples and Yaak collection
   - Reuse existing HetznerService and validation rules
   - Support IPv4/IPv6 configuration
   - Support cloud-init scripts
   - Smart SSH key management with deduplication"
   ```

4. **Create Pull Request**:
   ```bash
   git push origin hetzner-api-provisioning
   gh pr create --title "Add Hetzner Server Provisioning API" \
                --body "$(cat docs/api/HETZNER_API_README.md)"
   ```

## Security Considerations

- ✅ Tokens encrypted at rest (using Laravel's encrypted cast)
- ✅ Team-based isolation enforced
- ✅ API tokens validated before storage
- ✅ Rate limiting handled by HetznerService
- ✅ No sensitive data in responses (token field hidden)
- ✅ Authorization checks match UI (admin-only for token operations)
- ✅ Extra field validation prevents injection attacks

## Compatibility

- **Laravel**: 12.x
- **PHP**: 8.4
- **Existing UI**: Fully compatible, shares same service layer
- **Database**: Uses existing schema (cloud_provider_tokens, servers tables)

## Future Enhancements

Potential additions:
- [ ] DigitalOcean server provisioning endpoints
- [ ] Server power management (start/stop/reboot)
- [ ] Server resize/upgrade endpoints
- [ ] Batch server creation
- [ ] Server templates/presets
- [ ] Webhooks for server events

## Support

For issues or questions:
- Check [hetzner-provisioning-examples.md](./hetzner-provisioning-examples.md) for usage examples
- Review test files for expected behavior
- See existing `HetznerService` for Hetzner API details
