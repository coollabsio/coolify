# Welcome

First of all, thank you for considering to contribute to my project! It means a lot 💜.

# Technical skills required

- Node.js / Javascript
- Svelte / SvelteKit
- Prisma.io

# Recommended Pull Request Guideline

- Fork the project
- Clone your fork repo to local
- Create a new branch
- Push to your fork repo
- Create a pull request: https://github.com/coollabsio/compare
- Write a proper description
- Open the pull request to review

# How to start after you set up your local fork?

This repository best with [pnpm](https://pnpm.io) due to the lock file. I recommend you should try and use `pnpm` as well, because it is cool and efficient!

You need to have [Docker Engine](https://docs.docker.com/engine/install/) installed locally.

## Setup development environment

- Copy `.env.template` to `.env` and set the `COOLIFY_APP_ID` environment variable to something cool.
- Install dependencies with `pnpm install`.
- Need to create a local SQlite database with `pnpm db:push`.
  - This will apply all migrations at `db/dev.db`.
- Seed the database with base entities with `pnpm db:seed`
- You can start coding after starting `pnpm dev`.

## Database migrations

During development, if you change the database layout, you need to run `pnpm db:push` to migrate the database and create types for Prisma. You also need to restart the development process.

If the schema is finalized, you need to create a migration file with `pnpm db:migrate <nameOfMigration>` where `nameOfMigration` is given by you. Make it sense. :)

## Tricky parts

- BullMQ, the queue system Coolify is using, cannot be hot reloaded. So if you change anything in the files related to it, you need to restart the development process. I'm actively looking of a different queue/scheduler library. I'm open for discussion!
