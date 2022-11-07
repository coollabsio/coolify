### Container based development flow (recommended and the easiest)

All you need is to 

1. Install [Docker Engine 20.11+](https://docs.docker.com/engine/install/) on your local machine
2. Run `pnpm dev:container`. 

It will build the base image for Coolify and start the development server inside Docker. 

All required ports (3000, 3001) will be exposed to your host.