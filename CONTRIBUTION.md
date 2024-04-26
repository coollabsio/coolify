# Contributing

> "First, thanks for considering to contribute to my project. 
  It really means a lot!" - [@andrasbacsai](https://github.com/andrasbacsai)

You can ask for guidance anytime on our 
[Discord server](https://coollabs.io/discord) in the `#contribution` channel.

## Code Contribution

### 1) Setup your development environment

- You need to have Docker Engine (or equivalent) [installed](https://docs.docker.com/engine/install/) on your system.
- For better DX, install [Spin](https://serversideup.net/open-source/spin/).

### 2) Set your environment variables

- Copy [.env.development.example](./.env.development.example) to .env.

## 3) Start & setup Coolify

- Run `spin up` - You can notice that errors will be thrown. Don't worry.
  - If you see weird permission errors, especially on Mac, run `sudo spin up` instead. 

### 4) Start development
You can login your Coolify instance at `localhost:8000` with `test@example.com` and `password`.

Your horizon (Laravel scheduler): `localhost:8000/horizon` - Only reachable if you logged in with root user.

Mails are caught by Mailpit: `localhost:8025`

## New Service Contribution
Check out the docs [here](https://coolify.io/docs/knowledge-base/add-a-service).

