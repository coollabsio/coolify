---
type: fixed
---
Ignored `is_directory: false` on Compose bind mounts, so Coolify created a directory instead of a file ([#3375](https://github.com/coollabsio/coolify/issues/3375))

- A long-syntax bind with `is_directory: false` and no `content:` is an empty file that you can fill in on the `Persistent Storage` page
- A deployment or start creates a missing file storage without content as an empty file before `docker compose up`, so Docker does not create a directory at its path
