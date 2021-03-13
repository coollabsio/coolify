# About

https://andrasbacsai.com/farewell-netlify-and-heroku-after-3-days-of-coding

# Features
- Deploy your application just by pushing code to git.
- Environment variables stored encrypted.
- Hassle-free self-hosting.
- Databases

# Upcoming features
- Backups & monitoring
- User analytics with privacy in mind
- And much more

# Screenshots

[Login](https://coollabs.io/products/coolify/login.jpeg)

[Dashboard](https://coollabs.io/products/coolify/dashboard.jpeg)

[General configuration](https://coollabs.io/products/coolify/configuration.jpeg)

[Settings](https://coollabs.io/products/coolify/settings.jpeg)

# Getting Started

Automatically: `sh <(curl -fsSL https://get.coollabs.io/install.sh) coolify`

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

## Updating process
### Update everything (proxy+coolify)
Updating proxy will cause downtime!
-  `bash install.sh all`

### Update coolify only
-  `bash install.sh coolify`

### Update proxy only
Updating proxy will cause downtime!
-  `bash install.sh proxy`

# Contact
- Email: hi@coollabs.io
- Chat: [Discord](https://discord.gg/bvS3WhR)

# License
This program is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. Please see the [LICENSE](/LICENSE) file in our repository for the full text.
