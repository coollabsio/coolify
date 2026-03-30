# WordPress + OpenLiteSpeed

A production-ready WordPress stack using [OpenLiteSpeed](https://openlitespeed.org/) as the web server, backed by **MariaDB 11** and served with **PHP 8.3 FPM**.

---

## Stack

| Service         | Image                                     | Role                            |
|-----------------|-------------------------------------------|---------------------------------|
| `openlitespeed` | `litespeedtech/openlitespeed:latest`      | Web server / reverse proxy      |
| `wordpress`     | `wordpress:php8.3-fpm-alpine`             | WordPress PHP-FPM application   |
| `mariadb`       | `mariadb:11`                              | Relational database             |

---

## Features

- **OpenLiteSpeed** — Lightweight, high-performance web server with native LiteSpeed Cache (LSCache) support for WordPress.
- **PHP 8.3 FPM** — Fast PHP processing via the official WordPress FPM Alpine image.
- **MariaDB 11** — Stable, well-supported relational database.
- **Persistent volumes** — WordPress files (`wordpress-data`), MariaDB data (`mariadb-data`), OLS logs (`ols-logs`), and OLS config (`ols-conf`) are all persisted across container restarts.
- **Coolify proxy compatible** — Traefik labels are preconfigured; HTTP traffic is automatically redirected to HTTPS with a Let's Encrypt certificate.
- **Proxy-aware WordPress config** — `WP_HOME`, `WP_SITEURL`, `FORCE_SSL_ADMIN`, and `HTTP_X_FORWARDED_PROTO` handling are set automatically via `WORDPRESS_CONFIG_EXTRA`.
- **Health checks** — All three services expose health checks so Coolify knows when each is truly ready.

---

## Environment Variables

| Variable                    | Default       | Description                                             |
|-----------------------------|---------------|---------------------------------------------------------|
| `SERVICE_FQDN_OPENLITESPEED`| *(required)*  | Domain name for the WordPress site                      |
| `SERVICE_USER_MYSQL`        | `wordpress`   | MariaDB username for WordPress                          |
| `SERVICE_PASSWORD_MYSQL`    | *(generated)* | MariaDB password for WordPress user                     |
| `SERVICE_PASSWORD_ROOT_MYSQL`| *(generated)*| MariaDB root password                                   |
| `MYSQL_DATABASE`            | `wordpress`   | Database name                                           |
| `WORDPRESS_TABLE_PREFIX`    | `wp_`         | WordPress DB table prefix                               |
| `WORDPRESS_DEBUG`           | `false`       | Enable `WP_DEBUG` (set `true` for dev only)             |

---

## Volumes

| Volume           | Mount path (container)       | Description                    |
|------------------|------------------------------|--------------------------------|
| `wordpress-data` | `/var/www/html` (WordPress)  | WordPress core files, uploads, plugins, themes |
| `wordpress-data` | `/var/www/vhosts` (OLS)      | Shared web root served by OpenLiteSpeed |
| `mariadb-data`   | `/var/lib/mysql`             | MariaDB data directory         |
| `ols-logs`       | `/usr/local/lsws/logs`       | OpenLiteSpeed access/error logs |
| `ols-conf`       | `/usr/local/lsws/conf`       | OpenLiteSpeed configuration    |

---

## Getting Started

1. In Coolify, navigate to **Services → New Service**.
2. Select **WordPress + OpenLiteSpeed** from the template list.
3. Set your **Domain** (`SERVICE_FQDN_OPENLITESPEED`) — e.g. `blog.example.com`.
4. Credentials are auto-generated; you can override them before deploying.
5. Click **Deploy** — Coolify will provision all three containers and issue a TLS certificate automatically.
6. Once deployed, visit your domain and complete the WordPress 5-minute install.

---

## Recommended Plugins

After installation, consider:

- **LiteSpeed Cache** — Works natively with OpenLiteSpeed for full-page caching, image optimisation, and CDN integration.
- **Wordfence Security** — Firewall and malware scanner.
- **UpdraftPlus** — Off-site backups.

---

## Notes

- The `litespeedtech/openlitespeed` image exposes port **8088** by default; the Traefik labels in the compose file route external traffic to that port.
- OpenLiteSpeed's admin panel is available on port **7080** inside the container. To expose it, add a separate Traefik router or access it via `coolify exec`.
- For high-traffic sites, increase `PHP_PROC` (number of PHP worker processes) in the service's environment variables.
