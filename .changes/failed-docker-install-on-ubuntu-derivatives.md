---
type: fixed
commit: d6acd9a3c5
---
Failed Docker install on Ubuntu derivatives such as Pop!_OS and Linux Mint, and on Raspbian 13 (Trixie) ([#9455](https://github.com/coollabsio/coolify/issues/9455))

- The fallback uses the Ubuntu repository with `UBUNTU_CODENAME` on Ubuntu derivatives
- It uses the Debian repository on Raspbian 13, which has no Docker repository of its own
