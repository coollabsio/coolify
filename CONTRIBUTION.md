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

## Add a new service
### Which service is eligable to add to Coolify?
The following statements needs to be true:

- Self-hostable
- Open-source
- Maintained (I do not want to add software full of bugs)

### Create Prisma / Database schema for the new service.
All data that needs to be persist for a service should be saved to the database in `cleartext` or `encrypted`.

very password/api key/passphrase needs to be encrypted. If you are not sure, whether it should be encrypted or not, just encrypt it.

Update Prisma schema in [src/apps/api/prisma/schema.prisma](https://github.com/coollabsio/coolify/blob/main/apps/api/prisma/schema.prisma).

- Add new model with the new service name.
- Make a relationship with `Service` model.
- In the `Service` model, the name of the new field should be with low-capital.
- If the service needs a database, define a `publicPort` field to be able to make it's database public, example field name in case of PostgreSQL: `postgresqlPublicPort`. It should be a optional field.

Once done, create Prisma schema with `pnpm db:push`.
> You may also need to restart `Typescript Language Server` in your IDE to get the new types.

### Add available versions 

Versions are hardcoded into Coolify at the moment and based on Docker image tags.
- Update `supportedServiceTypesAndVersions` function [here](apps/api/src/lib/services/supportedVersions.ts)

### Include the new service in queries

At [here](apps/api/src/lib/services/common.ts) in `includeServices` function add the new table name, so it will be included in all places in the database queries where it is required.

### Define auto-generated fields

At [here](apps/api/src/lib/services/common.ts) in `configureServiceType` function add the initial auto-generated details such as password, users etc, and the encryption process of secrets (if applicable).

### Define input field details

At [here](apps/api/src/lib/services/serviceFields.ts) add details about the input fields shown in the UI, so every component (API/UI) will know what to do with the values (decrypt/show it by default/readonly/etc).

### Define the start process

- At [here](apps/api/src/lib/services/handlers.ts), define how the service should start. It could be complex and based on `docker-compose` definitions.

> See `startUmamiService()` function as example.

- At [here](apps/api/src/routes/api/v1/services/handlers.ts), add the new start service process to `startService` function.

### Define the deletion process

[Here](apps/api/src/lib/services/common.ts) in `removeService` add the database deletion process.

### Custom logo

- At [here](apps/ui/src/lib/components/svg/services) add the service custom log as a Svelte component and export it [here](apps/ui/src/lib/components/svg/services/index.ts).

>  SVG is recommended, but you can use PNG as well. It should have the `isAbsolute` variable with the suitable CSS classes, primarily for sizing and positioning.

- At [here](apps/ui/src/lib/components/svg/services/ServiceIcons.svelte) include the new logo with `isAbsolute` property.

- At [here](apps/ui/src/routes/services/[id]/_ServiceLinks.svelte) add links to the documentation of the service.

### Custom fields on the UI
By default the URL and name are shown on the UI. Everything else needs to be added [here](apps/ui/src/routes/services/[id]/_Services/_Services.svelte)

> If you need to show more details on the frontend, such as users/passwords, you need to add Svelte component [here](apps/ui/src/routes/services/[id]/_Services) with an underscore. For example, see other [here](apps/ui/src/routes/services/[id]/_Services/_Umami.svelte).

Good job! 👏