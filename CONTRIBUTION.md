# Contribution

First, thanks for considering to contribute to my project. It really means a lot! :)

You can ask for guidance anytime on our Discord server in the #contribution channel.

## Setup your development environment
### Container based development flow (recommended and the easiest)
All you need is to intall [Docker Engine 20.11+](https://docs.docker.com/engine/install/) on your local machine and run `pnpm dev:container`. It will build the base image for Coolify and start the development server inside Docker. All required ports (3000, 3001) will be exposed to your host.

### Github codespaces

If you have github codespaces enabled then you can just create a codespace and run `pnpm dev` to run your the dev environment. All the required dependencies and packages has been configured for you already.

### Gitpod
1. Use [container based development flow](#container-based-development-flow-easiest)
2. Or setup your workspace manually:

Create a workspace from this repository, run `pnpm install && pnpm db:push && pnpm db:seed` and then `pnpm dev`. All the required dependencies and packages has been configured for you already.

> Some packages, just `pack` are not installed in this way. You cannot test all the features. Please use the [container based development flow](#container-based-development-flow-easiest).

### Local Machine
> At the moment, Coolify `doesn't support Windows`. You must use `Linux` or `MacOS` or consider using Gitpod or Github Codespaces.

Install all the prerequisites manually to your host system. If you would not like to install anything, I suggest to use the [container based development flow](#container-based-development-flow-easiest).

- Due to the lock file, this repository is best with [pnpm](https://pnpm.io). I recommend you try and use `pnpm` because it is cool and efficient!
- You need to have [Docker Engine](https://docs.docker.com/engine/install/) installed locally.
- You need to have [Docker Compose Plugin](https://docs.docker.com/compose/install/compose-plugin/) installed locally.
- You need to have [GIT LFS Support](https://git-lfs.github.com/) installed locally.

Optional:
- To test Heroku buildpacks, you need [pack](https://github.com/buildpacks/pack) binary installed locally.

### Inside a Docker container
`WIP`

## Setup Coolify
- Copy `apps/api/.env.template` to `apps/api/.env.template` and set the `COOLIFY_APP_ID` environment variable to something cool.
- `pnpm install` to install dependencies.
- `pnpm db:push` to o create a local SQlite database.

   This will apply all migrations at `db/dev.db`.

- `pnpm db:seed` seed the database.
- `pnpm dev` start coding.

## Technical skills required

- **Languages**: Node.js / Javascript / Typescript
- **Framework JS/TS**: [SvelteKit](https://kit.svelte.dev/) & [Fastify](https://www.fastify.io/)
- **Database ORM**: [Prisma.io](https://www.prisma.io/)
- **Docker Engine API**

## How to add a new service?
You can find all details [here](https://github.com/coollabsio/coolify-community-templates)