# Welcome

First of all, thank you for considering contributing to my project! It means a lot 💜.

# Technical skills required

- Node.js / Javascript
- Svelte / SvelteKit
- Prisma.io / SQL

# Recommended Pull Request Guideline

- Fork the project
- Clone your fork repo to local
- Create a new branch
- Push to your fork repo
- Create a pull request: https://github.com/coollabsio/compare
- Write a proper description
- Open the pull request to review against `next` branch

---

# How to start after you set up your local fork?

Due to the lock file, this repository is best with [pnpm](https://pnpm.io). I recommend you try and use `pnpm` because it is cool and efficient!

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

- BullMQ, the queue system Coolify uses, cannot be hot reloaded. So if you change anything in the files related to it, you need to restart the development process. I'm actively looking for a different queue/scheduler library. I'm open to discussion!

---

# How to add new services

You can add any open-source and self-hostable software (service/application) to Coolify if the following statements are true:

- Self-hostable (obviously)
- Open-source
- Maintained (I do not want to add software full of bugs)

## Backend

I use MinIO as an example.

You need to add a new folder to [src/routes/services/[id]](src/routes/services/[id]) with the low-capital name of the service. It should have three files with the following properties:

1. `index.json.ts`: A POST endpoint that updates Coolify's database about the service.

   Basic services only require updating the URL(fqdn) and the name of the service.

2. `start.json.ts`: A start endpoint that setups the docker-compose file (for Local Docker Engines) and starts the service.

   - To start a service, you need to know Coolify supported images and tags of the service. For that you need to update `supportedServiceTypesAndVersions` function at [src/lib/components/common.ts](src/lib/components/common.ts).

     Example JSON:

     ```js
     {
       // Name used to identify the service in Coolify
       name: 'minio',
       // Fancier name to show to the user
       fancyName: 'MinIO',
       // Docker base image for the service
       baseImage: 'minio/minio',
       // Usable tags
       versions: ['latest'],
       // Which tag is the recommended
       recommendedVersion: 'latest',
       // Application's default port, MinIO listens on 9001 (and 9000, more details later on)
       ports: {
         main: 9001
       }
     },
     ```

   - You need to define a compose file as `const composeFile: ComposeFile` found in [src/routes/services/[id]/minio/start.json.ts](src/routes/services/[id]/minio/start.json.ts)

     **IMPORTANT:** It should contain `all the default environment variables` that are required for the service to function correctly and `all the volumes to persist data` in restarts.

   - You could also define an `HTTP` or `TCP` proxy for every other port that should be proxied to your server. (See `startHttpProxy` and `startTcpProxy` functions in [src/lib/haproxy/index.ts](src/lib/haproxy/index.ts))

3. `stop.json.ts` A stop endpoint that stops the service.

   It needs to stop all the services by their container name and proxies (if applicable).

4. You need to add the automatically generated variables (passwords, users, etc.) for the new service at [src/lib/database/services.ts](src/lib/database/services.ts), `configureServiceType` function.

## Frontend

1. You need to add a custom logo at [src/lib/components/svg/services/](src/lib/components/svg/services/) as a svelte component.

   SVG is recommended, but you can use PNG as well. It should have the `isAbsolute` variable with the suitable CSS classes, primarily for sizing and positioning.

2. You need to include it the logo at [src/routes/services/index.svelte](src/routes/services/index.svelte) with `isAbsolute` and [src/lib/components/ServiceLinks.svelte](src/lib/components/ServiceLinks.svelte) with a link to the docs/main site of the service.

3. By default the URL and the name frontend forms are included in [src/routes/services/[id]/\_Services/\_Services.svelte](src/routes/services/[id]/_Services/_Services.svelte).

   If you need to show more details on the frontend, such as users/passwords, you need to add Svelte component to [src/routes/services/[id]/\_Services](src/routes/services/[id]/_Services) with an underscore. For example, see other files in that folder.

   You also need to add the new inputs to the `index.json.ts` file of the specific service, like for MinIO here: [src/routes/services/[id]/minio/index.json.ts](src/routes/services/[id]/minio/index.json.ts)
