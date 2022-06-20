# Coolify

An open-source & self-hostable Heroku / Netlify alternative.

## Live Demo

https://demo.coolify.io/

(If it is unresponsive, that means someone overloaded the server. 🙃)

## Feedback

If you have a new service / build pack you would like to add, raise an idea [here](https://feedback.coolify.io/) to get feedback from the community!

## How to install

Installation is automated with the following command:

```bash
wget -q https://get.coollabs.io/coolify/install.sh -O install.sh; sudo bash ./install.sh
```

If you would like no questions during installation:

```bash
wget -q https://get.coollabs.io/coolify/install.sh -O install.sh; sudo bash ./install.sh -f
```

For more details goto the [docs](https://docs.coollabs.io/coolify/installation).

## Features

### Git Sources

You can use the following Git Sources to be auto-deployed to your Coolifyt instance! (Self-hosted versions are also supported.)

- Github
- GitLab
- Bitbucket (WIP)

### Destinations

You can deploy your applications to the following destinations:

- Local Docker Engine
- Remote Docker Engine (WIP)
- Kubernetes (WIP)

### Applications

These are the predefined build packs, but with the Docker build pack, you can host anything that is hostable with a single Dockerfile.

- Static sites
- NodeJS
- VueJS
- NuxtJS
- NextJS
- React/Preact
- Gatsby
- Svelte
- PHP
- Laravel
- Rust
- Docker
- Python
- Deno

### Databases

One-click database is ready to be used internally or shared over the internet:

- MongoDB
- MariaDB
- MySQL
- PostgreSQL
- CouchDB
- Redis

### One-click services

You can host cool open-source services as well:

- [WordPress](https://docs.coollabs.io/coolify/services/wordpress)
- [Ghost](https://ghost.org)
- [Plausible Analytics](https://docs.coollabs.io/coolify/services/plausible-analytics)
- [NocoDB](https://nocodb.com)
- [VSCode Server](https://github.com/cdr/code-server)
- [MinIO](https://min.io)
- [VaultWarden](https://github.com/dani-garcia/vaultwarden)
- [LanguageTool](https://languagetool.org)
- [n8n](https://n8n.io)
- [Uptime Kuma](https://github.com/louislam/uptime-kuma)
- [MeiliSearch](https://github.com/meilisearch/meilisearch)
- [Umami](https://github.com/mikecao/umami)
- [Fider](https://fider.io)
- [Hasura](https://hasura.io)
- [Reacher](https://reacher.email/)

## Migration from v1

A fresh installation is necessary. v2 is not compatible with v1.

## Support

- Twitter: [@andrasbacsai](https://twitter.com/andrasbacsai)
- Telegram: [@andrasbacsai](https://t.me/andrasbacsai)
- Email: [andras@coollabs.io](mailto:andras@coollabs.io)
- Discord: [Invitation](https://discord.gg/xhBCC7eGKw)

## Contribute

See [our contribution guide](./CONTRIBUTING.md).

## License

This program is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. Please see the [LICENSE](/LICENSE) file in our repository for the full text.
