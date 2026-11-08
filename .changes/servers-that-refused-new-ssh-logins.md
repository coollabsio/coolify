---
type: fixed
commit: 5431d4954d
---
Servers that refused new SSH logins with "Maximum number of sessions (8192) reached", because each connection check opened three SSH logins ([#9148](https://github.com/coollabsio/coolify/issues/9148))

- The Docker and Docker Compose version checks use the multiplexed SSH connection, so each check opens one login
- To remove the stuck sessions, run `systemctl restart systemd-logind` on the server
