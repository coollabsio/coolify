---
type: fixed
commit: d3ef4d1c57
---
Failed sign-up and login in the Rybbit service template, `/api` requests got a 404 error ([#9055](https://github.com/coollabsio/coolify/issues/9055))

- The template routes `/api` on the same domain to the backend on port 3001 and keeps the `/api` prefix
- For an existing service, add `SERVICE_URL_RYBBIT_3001=/api` to `rybbit_backend`, move it above `rybbit` in the Docker Compose file, set `Path prefixes` to `Keep prefixes` in the advanced settings of the backend and redeploy
