# About

https://andrasbacsai.com/farewell-netlify-and-heroku-after-3-days-of-coding

# Features
- Deploy your Node.js, static sites, PHP or any custom application (with custom Dockerfile) just by pushing code to git.
- Hassle-free installation and upgrade process.
- One-click MongoDB, MySQL, PostgreSQL, CouchDB deployments!

# Upcoming features
- Backups & monitoring.
- User analytics with privacy in mind.
- And much more (see [Roadmap](https://github.com/coollabsio/coolify/projects/1)).


# FAQ
Q: What is a buildpack?

A: It defines your application's final form. 
`Static` means that it will be hosted as a static site.
`NodeJs` means that it will be started as a node application.

# Screenshots

[Login](https://coollabs.io/coolify/login.jpg)

[Applications](https://coollabs.io/coolify/applications.jpg)

[Databases](https://coollabs.io/coolify/databases.jpg)

[Configuration](https://coollabs.io/coolify/configuration.jpg)

[Settings](https://coollabs.io/coolify/settings.jpg)

[Logs](https://coollabs.io/coolify/logs.jpg)

# Getting Started

Automatically: `/bin/bash -c "$(curl -fsSL https://get.coollabs.io/coolify/install.sh)"`

Manually:
### Requirements before installation
- [Docker](https://docs.docker.com/engine/install/) version 20+  
- Docker in [swarm mode enabled](https://docs.docker.com/engine/reference/commandline/swarm_init/) (should be set manually before installation)
- A [MongoDB](https://docs.mongodb.com/manual/installation/) instance.
  - We have a [simple installation](https://github.com/coollabsio/infrastructure/tree/main/mongo) if you need one
- A configured DNS entry (see `.env.template`)
- [Github App](https://docs.github.com/en/developers/apps/creating-a-github-app)

  - GitHub App name: could be anything weird
  - Homepage URL: https://yourdomain

  Identifying and authorizing users: 
  - Callback URL: https://yourdomain/api/v1/login/github/app
  - Request user authorization (OAuth) during installation -> Check!

  Webhook:
  - Active -> Check!
  - Webhook URL: https://yourdomain/api/v1/webhooks/deploy
  - Webhook Secret: it should be super secret

  Repository permissions:
  - Contents: Read-only
  - Metadata: Read-only
  
  User permissions: 
  - Email: Read-only

  Subscribe to events: 
  - Push -> Check!

### Installation
- Clone this repository: `git clone git@github.com:coollabsio/coolify.git`
- Set `.env` (see `.env.template`)
- Installation: `bash install.sh all`

## Manual updating process (You probably never need to do this!)
### Update everything (proxy+coolify)
-  `bash install.sh all`

### Update coolify only
-  `bash install.sh coolify`

### Update proxy only
-  `bash install.sh proxy`

# Contact
- Twitter: [@andrasbacsai](https://twitter.com/andrasbacsai)
- Telegram: [@andrasbacsai](https://t.me/andrasbacsai)
- Email: [andras@coollabs.io](mailto:andras@coollabs.io)

# License
This program is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. Please see the [LICENSE](/LICENSE) file in our repository for the full text.
