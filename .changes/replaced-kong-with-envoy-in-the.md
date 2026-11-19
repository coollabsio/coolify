---
type: changed
---
Replaced Kong with Envoy in the `Supabase` one-click service and updated it to the Supabase self-hosted release 0.8.4 ([#12000](https://github.com/coollabsio/coolify/issues/12000))

- Existing Supabase services keep Kong and their images. Only new services use Envoy, PostgreSQL 17.11 and the new images
