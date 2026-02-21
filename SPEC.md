# SPEC.md - Issue #8266 Email Server Template

## Issue Summary
Add a reusable email server stack/template for Coolify based on Docker Mailserver.

## Requirements
1. Reusable service template for self-hosted email server
2. Based on Docker Mailserver or Mailu
3. Persistent volumes for mailboxes, config, certificates
4. Compatible with Coolify's proxy and certificate management

## Files to Create/Modify
- `templates/compose/docker-mailserver.yaml` - New template file

## Template Structure (following Coolify format)
```yaml
# documentation: <docs-url>
# slogan: <short-description>
# category: email
# tags: <tags>
# logo: svgs/<icon>.svg
# port: <main-port>

services:
  mailserver:
    image: docker/mailserver:latest
    ports:
      - "25:25"    # SMTP
      - "587:587"  # SMTP submission
      - "993:993"  # IMAPS
    volumes:
      - "mail-data:/var/mail"
      - "mail-state:/var/mail-state"
    environment:
      - SSL_TYPE=letsencrypt
    healthcheck:
      test: ["CMD", "nc", "-z", "localhost", "25"]
```

## Implementation Notes
- Use Coolify's volume system (not bind mounts for data)
- Reference Coolify's certificate system via environment variables
- Support standard email ports (25, 587, 993)
- Include proper healthcheck
- Follow existing template comments format

## Verification
- Template YAML syntax valid
- All required metadata fields present
- Compatible with Docker Mailserver latest image
