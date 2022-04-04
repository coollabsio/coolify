# 👋 Welcome

First of all, thank you for considering contributing to my project! It means a lot 💜.

## 🙋 Want to help?

If you begin in GitHub contribution, you can find the [first contribution](https://github.com/firstcontributions/first-contributions) and follow this guide.

Follow the [introduction](#introduction) to get started then start contributing!

This is a little list of what you can do to help the project:

- [🧑‍💻 Develop your own ideas](#developer-contribution)
- [🌐 Translate the project](#translation)
- [📄 Help sorting out the issues](#help-sorting-out-the-issues)
- [🎯 Test Pull Requests](#test-pull-requests)
- [✒️ Help with the documentation](#help-with-the-documentation)

## 👋 Introduction

🔴 At the moment, Coolify **doesn't support Windows**. You must use Linux or MacOS.

#### Recommended Pull Request Guideline

- Fork the project
- Clone your fork repo to local
- Create a new branch
- Push to your fork repo
- Create a pull request: https://github.com/coollabsio/compare
- Write a proper description
- Click "Change to draft"

#### Setup a local development environment

- Copy `.env.template` to `.env` and set the `COOLIFY_APP_ID` environment variable to something cool.
- Install dependencies with `pnpm install`.
- Need to create a local SQlite database with `pnpm db:push`.
  - This will apply all migrations at `db/dev.db`.
- Seed the database with base entities with `pnpm db:seed`
- You can start coding after starting `pnpm dev`.

#### How to start after you set up your local fork?

This repository works better with [pnpm](https://pnpm.io) due to the lock file. I recommend you to give it a try and use `pnpm` as well because it is cool and efficient!

You need to have [Docker Engine](https://docs.docker.com/engine/install/) installed locally.

## 🧑‍💻 Developer contribution

### Technical skills required

- **Languages**: Node.js / Javascript / Typescript
- **Framework JS/TS**: Svelte / SvelteKit
- **Database ORM**: Prisma.io

### Database migrations

During development, if you change the database layout, you need to run `pnpm db:push` to migrate the database and create types for Prisma. You also need to restart the development process.

If the schema is finalized, you need to create a migration file with `pnpm db:migrate <nameOfMigration>` where `nameOfMigration` is given by you. Make it sense. :)

### Tricky parts

- BullMQ, the queue system Coolify is using, cannot be hot reloaded. So if you change anything in the files related to it, you need to restart the development process. I'm actively looking of a different queue/scheduler library. I'm open for discussion!

## 🌐 Translate the project

The project use [sveltekit-i18n](https://github.com/sveltekit-i18n/lib) to translate the project.
It follows the [ISO 639-1](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) to name languages.

### Installation

You must have gone throw all the [intro](#introduction) steps before you can start translating.

It's only an advice, but I recommend you to use:

- Visual Studio Code
- [i18n Ally for Visual Studio Code](https://marketplace.visualstudio.com/items?itemName=Lokalise.i18n-ally): ideal to see the progress of the translation.
- [Svelte for VS Code](https://marketplace.visualstudio.com/items?itemName=svelte.svelte-vscode): to get the syntax color for the project

### Adding a language

If your language doesn't appear in the [locales folder list](src/lib/locales/), follow the step below:

1.  In `src/lib/locales/`, Copy paste `en.json` and rename it with your language (eg: `cz.json`).
2.  In the [lang.json](src/lib/lang.json) file, add a line after the first bracket (`{`) with `"ISO of your language": "Language",` (eg: `"cz": "Czech",`).
3.  Have fun translating!

### Additionnal pull requests steps

Please add the emoji 🌐 to your pull request title to indicate that it is a translation.

## 📄 Help sorting out the issues

ToDo

## 🎯 Test Pull Requests

ToDo

## ✒️ Help with the documentation

ToDo
