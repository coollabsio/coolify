---
type: fixed
---
Showed `419 Page Expired` on the login page on port 8000 after a visit to the same host over HTTPS ([#4313](https://github.com/coollabsio/coolify/issues/4313))

- When `SESSION_SECURE_COOKIE` is not set, plain HTTP uses its own session and XSRF cookies, so the Secure cookies of HTTPS cannot block them. You must sign in again one time on plain HTTP after the upgrade
