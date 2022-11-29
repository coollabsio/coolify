# Contributing

> "First, thanks for considering to contribute to my project. 
  It really means a lot! 😁" - [@andrasbacsai](https://github.com/andrasbacsai)

You can ask for guidance anytime on our 
[Discord server](https://coollabs.io/discord) in the `#contribution` channel.

You'll need a set of skills to [get started](docs/contribution/GettingStarted.md).

## 1) Setup your development environment

- 🌟 [Container based](docs/dev_setup/Container.md) &larr; *Recomended*
- 📦 [DockerContainer](docs/dev_setup/DockerContiner.md) *WIP
- 🐙 [Github Codespaces](docs/dev_setup/GithubCodespaces.md)
- ☁️ [GitPod](docs/dev_setup/GitPod.md)
- 🍏 [Local Mac](docs/dev_setup/Mac.md)

## 2) Basic requirements

- [Install Pnpm](https://pnpm.io/installation)
- [Install Docker Engine](https://docs.docker.com/engine/install/)
- [Setup Docker Compose Plugin](https://docs.docker.com/compose/install/compose-plugin/)
- [Setup GIT LFS Support](https://git-lfs.github.com/)

## 3) Setup Coolify

- Copy `apps/api/.env.example` to `apps/api/.env` 
- Edit `apps/api/.env`, set the `COOLIFY_APP_ID` environment variable to something cool.
- Run `pnpm install` to install dependencies.
- Run `pnpm db:push` to o create a local SQlite database. This will apply all migrations at `db/dev.db`.
- Run `pnpm db:seed` seed the database.
- Run `pnpm dev` start coding.

```sh
# Or... Copy and paste commands bellow:
cp apps/api/.env.example apps/api/.env
pnpm install
pnpm db:push
pnpm db:seed
pnpm dev
```

## 4) Start Coding

You should be able to access `http://localhost:3000`.

1. Click `Register` and setup your first user.