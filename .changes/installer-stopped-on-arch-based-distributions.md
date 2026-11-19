---
type: fixed
---
Installer stopped on Arch based distributions that it did not know by name, such as Garuda ([#5297](https://github.com/coollabsio/coolify/issues/5297))

- The installer treats each distribution with `arch` in `ID_LIKE` of `/etc/os-release` as Arch Linux
