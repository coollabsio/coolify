# Flux TLS Design

## Goal

Secure every direct Sentinel-to-Flux connection with TLS while keeping SSH as the bootstrap and recovery channel.

## Trust model

Each Coolify installation owns one private certificate authority (CA). The CA is valid for 100 years. Coolify stores its private key encrypted and never sends it to Flux or Sentinel as configuration data. Flux receives a leaf certificate and key. Sentinel receives only a versioned CA trust bundle.

This private CA is used for all Flux endpoints: public domains, private domains, IPv4, IPv6, HTTP dashboard installations, self-hosted installations, and Coolify Cloud. Dashboard TLS and Flux TLS are independent.

## Certificates

- Installation CA validity: 100 years.
- Flux leaf validity: 90 days.
- Automatic leaf renewal starts with 30 days remaining.
- Leaf SANs contain the exact configured Flux DNS name or IP address.
- Normal leaf renewal and key replacement use the same CA and need no Sentinel update.
- Private keys use encrypted database storage and `0600` materialized files.
- Public certificates and CA bundles use `0644` files.
- File replacement is atomic.

## Bootstrap and recovery

Coolify uses SSH to install the Sentinel binary, its existing `PUSH_ENDPOINT` and `TOKEN`, the CA bundle, and the expected Flux TLS server name. The assignment endpoint returns the Flux URL and trust-bundle version, but it is not the initial trust source. This keeps HTTP dashboard installations safe from CA substitution during assignment discovery.

SSH stays available for certificate repair, endpoint repair, missed rotations, and full Flux failure. Flux does not replace SSH as the emergency recovery channel.

## Runtime rules

Production Sentinel rejects plaintext Flux URLs. Development plaintext is allowed only when both processes have an explicit development-only option. The development stack uses TLS by default.

Sentinel verifies:

- the leaf chain against the installed Coolify CA bundle;
- the DNS name, IPv4 address, or IPv6 address from the assignment;
- certificate validity;
- the assignment trust-bundle version against its installed bundle metadata.

Flux fails to start if TLS configuration is missing, incomplete, unreadable, expired, mismatched, or invalid. Development plaintext requires an explicit opt-in.

## Endpoint changes and leaf renewal

Coolify creates and validates the replacement certificate first, writes it atomically, restarts Flux, checks its health, and then changes assignments. A failed health check restores the prior leaf files. Endpoint changes reuse the same CA.

## CA rotation

Rotation is staged:

1. Create CA version `N+1`.
2. Distribute a bundle with CA `N` and `N+1` through a signed control command; use SSH when Flux is unavailable.
3. Sentinel writes the bundle atomically, reloads it, and reports bundle version `N+1`.
4. Flux changes to a leaf signed by CA `N+1`.
5. Coolify verifies reconnections.
6. Keep both CAs during the overlap period.
7. Remove CA `N` only after required servers acknowledge the new version or an administrator explicitly forces retirement.

Offline servers remain visible as pending. A server that misses forced retirement requires SSH repair.

## Persistence

Coolify stores CA versions, encrypted private keys, certificates, validity dates, state, and active version in the database. Normal Coolify database backup and `APP_KEY` recovery preserve the CA. Materialized Flux leaf files live in a private persistent volume and can be recreated from database state.

Sentinel stores its CA bundle and bundle version under `/etc/coolify`. Command assignments, credentials, connection state, and read-only command deduplication remain in memory.

## Development verification

The local CA signs a certificate with `flux`, `coolify-flux`, `localhost`, `127.0.0.1`, and `::1` SANs. The systemd testing host receives the CA through the same installer path as remote servers. The existing ping proves the complete TLS path.

Automated tests cover valid DNS and IP certificates, wrong CA, wrong identity, expired certificates, missing files, plaintext production rejection, leaf renewal, dual-CA overlap, bundle acknowledgement, and SSH repair.

## Rollout

All TLS work remains behind `SENTINEL_HOST_ENABLED`. Existing v4 Sentinel containers and SSH management are unchanged. TLS must pass the development ping before additional Flux commands are added.
