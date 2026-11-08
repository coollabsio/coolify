---
type: fixed
commit: e7e952fcad
---
Rebuilt Docker layers when a preview comes or goes, and unreachable hosts on a build server ([#12452](https://github.com/coollabsio/coolify/issues/12452))

- Preview containers and the hosts of the deployment server stay out of `--add-host` on a build server
- A setting of the application turns off the network hosts during the build
