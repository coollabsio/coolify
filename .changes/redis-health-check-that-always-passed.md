---
type: fixed
commit: 878d77bd66
---
Redis health check that always passed, because it did not ping the database

- The check pings with the password and requires `PONG`
