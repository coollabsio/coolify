---
type: fixed
---
Rebuilt images on `Restart` of Dockerfile applications with `Disable Build Cache` on, and of applications with an inline Dockerfile ([#5748](https://github.com/coollabsio/coolify/issues/5748))

- A restart starts the existing image when no build setting changed. A restart that must build still builds without the cache
