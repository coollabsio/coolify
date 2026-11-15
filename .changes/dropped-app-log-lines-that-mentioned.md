---
type: fixed
---
Dropped app log lines that mentioned `127.0.0.1` in the New Relic and Axiom log drains

- Only access log lines that start with `127.0.0.1`, such as health check requests, are filtered out
