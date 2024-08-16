# Contributing

> "First, thanks for considering to contribute to my project. 
  It really means a lot!" - [@andrasbacsai](https://github.com/andrasbacsai)

You can ask for guidance anytime on our 
[Discord server](https://coollabs.io/discord) in the `#contribution` channel.

## Code Contribution

### 1) Setup your development environment

- You need to have Docker Engine (or equivalent) [installed](https://docs.docker.com/engine/install/) on your system.
- If you are using a Mac, I highly recommend installing [Orbsatck](https://orbstack.dev/download) as a much faster alternative and complete replacement to Docker Desktop.
- For better DX, install [Spin](https://serversideup.net/open-source/spin/docs).

### 2) Set your environment variables

- Copy [.env.development.example](./.env.development.example) to .env.
- Make sure to set the DB_HOST environment variable to the Postgres container IP or, if using Orbstack, use the container name (e.g., `postgres.coolify.orb.local`) to make sure that the DB Migrations work.

## 3) Start & setup Coolify

- Run `spin up` - You can notice that errors will be thrown. Don't worry.
  - If you see weird permission errors, especially on Mac, run `sudo spin up` instead.

## 4) Install php to make sure you can do DB migrations (optional)

### 5) Start development
You can login your Coolify instance at `localhost:8000` with `test@example.com` and `password`.

Your horizon (Laravel scheduler): `localhost:8000/horizon` - Only reachable if you logged in with root user.

Mails are caught by Mailpit: `localhost:8025`


## New Service Contribution
Check out the docs [here](https://coolify.io/docs/knowledge-base/add-a-service).
