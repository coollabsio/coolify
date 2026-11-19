---
type: fixed
---
Unhealthy SQLite databases and failed writes of connected applications with `database is locked`, because the health check locked the database

- New SQLite databases use the image `peakimages/sqlite:3.54.1-v0.3.0`, whose health check reads the database without a lock
- Moved the home of `nonroot` from `/var/lib/sqlite` to `/home/nonroot`, `sqlite3` runs the `.sqliterc` of the home on every start, and every user can write `/var/lib/sqlite`
  - `sqlite3` reads its startup file from `/home/nonroot/.sqliterc`, which only `nonroot` can write
  - The working directory stays `/var/lib/sqlite`
