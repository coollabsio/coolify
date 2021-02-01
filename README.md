**WARNING**: It's still in beta, but I would like to ship it as soon as possible, without overthinking everything - as a mostly do. 😁

It is probably full of bugs, spaghetti code, etc. Just ignore them!

# About

https://andrasbacsai.com/farewell-netlify-and-heroku-after-3-days-of-coding

# Features
- Deploy your application just by pushing code to git.
- Environment variables stored encrypted.
- Hassle-free self-hosting.

# Upcoming features
- Backups & monitoring
- Scalable environment
- Databases

# Screenshots

The design is simple.

[Login](https://coolify.coollabs.io/assets/login.png)

[Dashboard](https://coolify.coollabs.io/assets/dashboard.png)

[General configuration](https://coolify.coollabs.io/assets/configuration.png)

[Build configuration](https://coolify.coollabs.io/assets/build_step.png)

[Secrets](https://coolify.coollabs.io/assets/secrets.png)

[Deployment Logs](https://coolify.coollabs.io/assets/logs.png)

# Getting Started
### Requirements before installation
- [Docker](https://docs.docker.com/engine/install/) version 20+  
- Docker in [swarm mode enabled](https://docs.docker.com/engine/reference/commandline/swarm_init/) (should be set manually before installation)
- A [MongoDB](https://docs.mongodb.com/manual/installation/) instance. (We have a [simple installation](https://github.com/coollabsio/infrastructure/tree/main/mongo) if you need one)
- A configured DNS entry for the webhook service (see `.env.template`)
- [Github OAuth App](https://docs.github.com/en/developers/apps/creating-an-oauth-app)
  - Authorization callback URL set to `https://<your domain>/api/v1/login/github/oauth`
- [Github App](https://docs.github.com/en/developers/apps/creating-a-github-app)
  - Callback URL set to `http://<your domain>/api/v1/login/github/app`

### Installation
- Clone this repository: `git clone git@github.com:coollabsio/coolify.git`
- Set `.env` (see `.env.template`)
- Installation: `bash install.sh all`

## Updating process
### Update everything (proxy+coolify)
Updating proxy cause downtime!
-  `bash install.sh all`

### Update coolify only
-  `bash install.sh coolify`

### Update proxy only
Updating proxy cause downtime!
-  `bash install.sh proxy`

# Contact
- Email: hi@coollabs.io
- Chat: [Discord](https://discord.gg/bvS3WhR)

# License
This program is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. Please see the [LICENSE](/LICENSE) file in our repository for the full text.
