---
type: fixed
---
Filled the server disk with an unbounded Coolify `laravel.log` file ([#3578](https://github.com/coollabsio/coolify/issues/3578))

- Coolify writes its log to one file for each day and keeps the files of the last 7 days. Set `LOG_DAILY_DAYS` in `/data/coolify/source/.env` to keep more or fewer days
