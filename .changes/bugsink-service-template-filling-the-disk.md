---
type: fixed
commit: ccaa04ce33
---
Bugsink service template filling the disk with MySQL binary logs until Bugsink stopped ([#10626](https://github.com/coollabsio/coolify/issues/10626))

- New services use PostgreSQL 17 and Bugsink 2
- To stop the growth in an existing service, run `PURGE BINARY LOGS BEFORE NOW();` in MySQL, then add `command: ['--disable-log-bin']` to the `mysql` service and restart it
