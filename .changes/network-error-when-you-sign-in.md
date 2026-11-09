---
type: fixed
commit: e9028e1338
---
"Network error" when you sign in with email in AppFlowy Web ([#10545](https://github.com/coollabsio/coolify/issues/10545))

- The template sets `GOTRUE_CORS_ALLOWED_HEADERS=redirect_to`
- For an existing service, add this variable to the `gotrue` service in the Docker Compose file and restart the service
