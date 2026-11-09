---
type: fixed
---
Image scans of large images that stopped because the scanner ran out of memory

- The scanner frees memory sooner when it gets close to its limit. Set a higher limit with `Scanner memory limit` on the `Vulnerabilities` page of a server, or with `image_scan_memory_mb` in the server image scanning API. The default stays 1 GB
