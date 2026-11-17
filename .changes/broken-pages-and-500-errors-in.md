---
type: fixed
---
Broken pages and `500` errors in new `Chaskiq` services ([#6094](https://github.com/coollabsio/coolify/issues/6094))

- The template now uses the public domain without port `3000` for `HOST` and `ASSET_HOST`, a valid `WS` URL, and keeps uploads and the admin setup on the storage volume
- The `Chaskiq` template is hidden from the service list, because its image gets no updates since October 2024. Existing `Chaskiq` services keep working
