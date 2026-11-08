---
type: fixed
commit: 9c6138fe18
---
Startup and the nightly cleanup of stuck resources stopping with "Allowed memory size exhausted" on instances with many large deployment logs ([#12435](https://github.com/coollabsio/coolify/issues/12435))

- Stuck deployments, tasks and backups are marked as failed, and the proxy configuration is set up
- Database backup pages and `Settings` > `Backup` do not load all executions on each request
