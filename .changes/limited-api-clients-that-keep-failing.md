---
type: security
commit: 13298591d8
---
Limited API clients that keep failing to 30 failed requests per minute for one token or IP

- More failed requests get `429`, `API_FAILED_REQUEST_RATE_LIMIT` sets the limit
- The team of the token gets one notification a day when the token reaches the limit
