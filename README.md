# Coolify

An open-source & self-hostable Heroku / Netlify alternative.

## Live Demo

https://demo.coolify.io/

(If it is unresponsive, that means someone overloaded the server. 🙃)

## How to install

Installation is automated with the following command:

```bash
/bin/bash -c "$(curl -fsSL https://get.coollabs.io/coolify/install.sh)"
```

If you would like no questions during installation


### Running locally

If you want to install locally, there are some simple steps to be aware.

Download an [Ubuntu](https://ubuntu.com/#download) ISO.

You can use [Ventoy](https://www.ventoy.net/en/index.html) to make a USB boot.

After installed, access your terminal through SSH or direct in the physical machine and make sure your system is up-to-date.

```bash
sudo apt update && sudo apt upgrade && sudo apt auto-remove
```

Then run those commands to install Coolify.

```bash
wget https://get.coollabs.io/coolify/install.sh
chmod +x install.sh
sudo ./install.sh
```

You will be asked if you would like the script to install docker. Press `Y` and wait until it finishes.

At the end of the installation, you'll see a link like `http://your-internet-ip:3000`. It's not possible to access it through this yet, so get your local IP with

```bash
ip a
```

and open your browser using `http://your-local-ip:3000`. Now you can set up Coolify your login in Coolify.

At this point you aren't able to config a domain, go to your router and:

1. Set a `static IP` for your machine.
2. In `Port Fowarding`, redirect port `80` and `443` to the IP you set up in the previous step.

Now you can config the domain in Coolify's settings. After that, you'll be able to access the dashboard by `http://your-domain.xyz`

>Check following sections before setting the domain.

### Setting a Domain

Coolify need a domain to work, so you must provide a valid one to it, and there are some things that you can do.

#### Using nip.io or sslip.io

If you don't own a domain and just want to use it as a test, you can either use [nip.io](nip.io) or [sslip.io](sslip.io).
You just need to get your internet IP and add `.nip.io` or `.sslip.io` at the end. e.g. `123.123.123.123.nip.io`, and it'll redirect to you IP.
Both services support wildcards. Just add it like `app.my-ip-address.nip.io`

>This method just works if your internet IP is static. **Check next step**

#### Using a owned domain

In this case, you need to buy a domain from a provider like `Hostinger` or `GoDaddy` and set two nameservers that are pointing to your IP address.

>If you have a static IP, you can either use [nip.io](nip.io) or [sslip.io](sslip.io). If not, there are some DDNS services that can dynamicaly update a domain with your current IP. e.g. (noip)[https://www.noip.com/].

Now you have to set up your wildcards. Add a new `A` record passing the wildcard desired, or `*` to redirect all wildcard requests to your server. Check the respective documentation from your service provider.

Now you must be ready to go!

## Features

### Git Sources

You can use the following Git Sources to be auto-deployed to your Coolifyt instance! (Self hosted versions also supported.)

- Github
- GitLab
- Bitbucket (WIP)

### Destinations

You can deploy your applications to the following destinations:

- Local Docker Engine
- Remote Docker Engine (WIP)
- Kubernetes (WIP)

### Applications

These are the predefined build packs, but with the Docker build pack, you can host basically anything that is hostable with a single Dockerfile.

- Static sites
- NodeJS
- VueJS
- NuxtJS
- NextJS
- React/Preact
- NextJS
- Gatsby
- Svelte
- PHP
- Rust
- Docker

### Databases

One-click database is ready to be used internally or shared over the internet:

- MongoDB
- MySQL
- PostgreSQL
- CouchDB
- Redis

### One-click services

You can host cool open-source services as well:

- [WordPress](https://wordpress.org)
- [Ghost](https://ghost.org)
- [Plausible Analytics](https://plausible.io)
- [NocoDB](https://nocodb.com)
- [VSCode Server](https://github.com/cdr/code-server)
- [MinIO](https://min.io)
- [VaultWarden](https://github.com/dani-garcia/vaultwarden)
- [LanguageTool](https://languagetool.org)
- [n8n](https://n8n.io)
- [Uptime Kuma](https://github.com/louislam/uptime-kuma)

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
