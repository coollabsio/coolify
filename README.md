### **In beta!**

# About

Link to blog post?

# Getting Started
### Requirements before installation
- [Docker](https://docs.docker.com/engine/install/) version 20+  
- Docker in [swarm mode enabled](https://docs.docker.com/engine/reference/commandline/swarm_init/) (should be set manually before installation)
- [MongoDB](https://docs.mongodb.com/manual/installation/) instance. (We have a simple installation if you need it [here](https://github.com/coollabsio/infrastructure))
- Set DNS name
- [Github OAuth App](https://docs.github.com/en/developers/apps/creating-an-oauth-app)
- [Github App](https://docs.github.com/en/developers/apps/creating-a-github-app)

### Installation
- Clone this repository: `git clone git@github.com:coollabsio/coolify.git`
- Set `.env` (see `.env.template`)
- Installation: `bash install.sh all`

## Updating process
### Update everything (proxy+coolify)
Updating proxy causing downtime!
-  `bash install.sh all`

### Update coolify only
-  `bash install.sh coolify`

### Update proxy only
Updating proxy causing downtime!
-  `bash install.sh proxy`

# Contact
- Email: hi@coollabs.io
- Chat: [Discord](https://discord.gg/bvS3WhR)

# License
This program is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. Please see the [LICENSE](/LICENSE) file in our repository for the full text.
