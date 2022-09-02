---
head:
  - - meta
    - name: description
      content: Coolify - Databases
  - - meta
    - name: keywords
      content: databases coollabs coolify 
  - - meta
    - name: twitter:card
      content: summary_large_image
  - - meta
    - name: twitter:site
      content: '@andrasbacsai'
  - - meta
    - name: twitter:title
      content: Coolify
  - - meta
    - name: twitter:description
      content: An open-source & self-hostable Heroku / Netlify alternative.
  - - meta
    - name: twitter:image
      content: https://cdn.coollabs.io/assets/coollabs/og-image-databases.png
  - - meta
    - property: og:type
      content: website
  - - meta
    - property: og:url
      content: https://coolify.io
  - - meta
    - property: og:title
      content: Coolify
  - - meta
    - property: og:description
      content: An open-source & self-hostable Heroku / Netlify alternative.
  - - meta
    - property: og:site_name
      content: Coolify
  - - meta
    - property: og:image
      content: https://cdn.coollabs.io/assets/coollabs/og-image-databases.png
---
# Contribution

First, thanks for considering to contribute to my project. It really means a lot! :)

You can ask for guidance anytime on our Discord server in the #contribution channel.

## Setup your development environment
### Github codespaces

If you have github codespaces enabled then you can just create a codespace and run `pnpm dev` to run your the dev environment. All the required dependencies and packages has been configured for you already.

### Gitpod

If you have a [Gitpod](https://gitpod.io), you can just create a workspace from this repository, run `pnpm install && pnpm db:push && pnpm db:seed` and then `pnpm dev`. All the required dependencies and packages has been configured for you already.

### Local Machine
> At the moment, Coolify `doesn't support Windows`. You must use `Linux` or `MacOS` or consider using Gitpod or Github Codespaces.

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

## Add a new service
### Which service is eligable to add to Coolify?
The following statements needs to be true:

- Self-hostable
- Open-source
- Maintained (I do not want to add software full of bugs)

### Create Prisma / Database schema for the new service.
All data that needs to be persist for a service should be saved to the database in `cleartext` or `encrypted`.

very password/api key/passphrase needs to be encrypted. If you are not sure, whether it should be encrypted or not, just encrypt it.

Update Prisma schema in [src/api/prisma/schema.prisma](https://github.com/coollabsio/coolify/blob/main/apps/api/prisma/schema.prisma).

- Add new model with the new service name.
- Make a relationship with `Service` model.
- In the `Service` model, the name of the new field should be with low-capital.
- If the service needs a database, define a `publicPort` field to be able to make it's database public, example field name in case of PostgreSQL: `postgresqlPublicPort`. It should be a optional field.
