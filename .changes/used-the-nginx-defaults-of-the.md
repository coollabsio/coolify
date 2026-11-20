---
type: security
---
Used the nginx defaults of the `serversideup/php` image in the `coolify` container

- PHP-FPM runs only existing PHP scripts outside `/storage`
- Responses carry the `X-Frame-Options`, `X-Content-Type-Options`, and `Referrer-Policy` headers
- Requests for dotfiles and for backup, config, log, SQL, and shell files return `403`
