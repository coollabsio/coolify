# 👋 Welcome

First of all, thank you for considering contributing to my project! It means a lot 💜.


## 🙋 Want to help?

If you begin in GitHub contribution, you can find the [first contribution](https://github.com/firstcontributions/first-contributions) and follow this guide.

Follow the [introduction](#introduction) to get started then start contributing!

This is a little list of what you can do to help the project:

- [🧑‍💻 Develop your own ideas](#developer-contribution)
- [🌐 Translate the project](#translation)

## 👋 Introduction

### Setup with Github codespaces

If you have github codespaces enabled then you can just create a codespace and run `pnpm dev` to run your the dev environment. All the required dependencies and packages has been configured for you already.

### Setup with Gitpod

If you have a [Gitpod](https://gitpod.io), you can just create a workspace from this repository, run `pnpm install && pnpm db:push && pnpm db:seed` and then `pnpm dev`. All the required dependencies and packages has been configured for you already.

### Setup locally in your machine

> 🔴 At the moment, Coolify **doesn't support Windows**. You must use Linux or MacOS. Consider using Gitpod or Github Codespaces.

#### Recommended Pull Request Guideline

- Fork the project
- Clone your fork repo to local
- Create a new branch
- Push to your fork repo
- Create a pull request: https://github.com/coollabsio/coolify/compare
- Write a proper description
- Open the pull request to review against `next` branch

---

# 🧑‍💻 Developer contribution
## Technical skills required

- **Languages**: Node.js / Javascript / Typescript
- **Framework JS/TS**: [SvelteKit](https://kit.svelte.dev/) & [Fastify](https://www.fastify.io/)
- **Database ORM**: [Prisma.io](https://www.prisma.io/)
- **Docker Engine API**

---

## How to start after you set up your local fork?

### Prerequisites
1. Due to the lock file, this repository is best with [pnpm](https://pnpm.io). I recommend you try and use `pnpm` because it is cool and efficient!

2. You need to have [Docker Engine](https://docs.docker.com/engine/install/) installed locally.
3. You need to have [Docker Compose Plugin](https://docs.docker.com/compose/install/compose-plugin/) installed locally.
4. You need to have [GIT LFS Support](https://git-lfs.github.com/) installed locally.

Optional:

4. To test Heroku buildpacks, you need [pack](https://github.com/buildpacks/pack) binary installed locally.

### Steps for local setup

1. Copy `apps/api/.env.template` to `apps/api/.env.template` and set the `COOLIFY_APP_ID` environment variable to something cool.
2. Install dependencies with `pnpm install`.
3. Need to create a local SQlite database with `pnpm db:push`.

   This will apply all migrations at `db/dev.db`.

4. Seed the database with base entities with `pnpm db:seed`
5. You can start coding after starting `pnpm dev`.

---

## Database migrations

During development, if you change the database layout, you need to run `pnpm db:push` to migrate the database and create types for Prisma. You also need to restart the development process.

If the schema is finalized, you need to create a migration file with `pnpm db:migrate <nameOfMigration>` where `nameOfMigration` is given by you. Make it sense. :)

---

## How to add new services

You can add any open-source and self-hostable software (service/application) to Coolify if the following statements are true:

- Self-hostable (obviously)
- Open-source
- Maintained (I do not want to add software full of bugs)

## Backend

There are 5 steps you should make on the backend side.

1. Create Prisma / database schema for the new service.
2. Add supported versions of the service.
3. Update global functions.
4. Create API endpoints.
5. Define automatically generated variables.

> I will use [Umami](https://umami.is/) as an example service.

### Create Prisma / Database schema for the new service.

You only need to do this if you store passwords or any persistent configuration. Mostly it is required by all services, but there are some exceptions, like NocoDB.

Update Prisma schema in [prisma/schema.prisma](prisma/schema.prisma).

- Add new model with the new service name.
- Make a relationship with `Service` model.
- In the `Service` model, the name of the new field should be with low-capital.
- If the service needs a database, define a `publicPort` field to be able to make it's database public, example field name in case of PostgreSQL: `postgresqlPublicPort`. It should be a optional field.

If you are finished with the Prisma schema, you should update the database schema with `pnpm db:push` command.

> You must restart the running development environment to be able to use the new model

> If you use VSCode/TLS, you probably need to restart the `Typescript Language Server` to get the new types loaded in the running environment.

### Add supported versions

Supported versions are hardcoded into Coolify (for now).

You need to update `supportedServiceTypesAndVersions` function at [apps/api/src/lib/services/supportedVersions.ts](apps/api/src/lib/services/supportedVersions.ts). Example JSON:

```js
     {
       // Name used to identify the service internally
       name: 'umami',
       // Fancier name to show to the user
       fancyName: 'Umami',
       // Docker base image for the service
       baseImage: 'ghcr.io/mikecao/umami',
       // Optional: If there is any dependent image, you should list it here
       images: [],
       // Usable tags
       versions: ['postgresql-latest'],
       // Which tag is the recommended
       recommendedVersion: 'postgresql-latest',
       // Application's default port, Umami listens on 3000
       ports: {
         main: 3000
       }
     }
```

### Add required functions/properties

1. Add the new service to the `includeServices` variable in [apps/api/src/lib/services/common.ts](apps/api/src/lib/services/common.ts), so it will be included in all places in the database queries where it is required.

```js
const include: any = {
	destinationDocker: true,
	persistentStorage: true,
	serviceSecret: true,
	minio: true,
	plausibleAnalytics: true,
	vscodeserver: true,
	wordpress: true,
	ghost: true,
	meiliSearch: true,
	umami: true // This line!
};
```

2. Update the database update query with the new service type to `configureServiceType` function in [apps/api/src/lib/services/common.ts](apps/api/src/lib/services/common.ts). This function defines the automatically generated variables (passwords, users, etc.) and it's encryption process (if applicable).

```js
[...]
else if (type === 'umami') {
		const postgresqlUser = cuid();
		const postgresqlPassword = encrypt(generatePassword());
		const postgresqlDatabase = 'umami';
		const hashSalt = encrypt(generatePassword(64));
		await prisma.service.update({
			where: { id },
			data: {
				type,
				umami: {
					create: {
						postgresqlDatabase,
						postgresqlPassword,
						postgresqlUser,
						hashSalt,
					}
				}
			}
		});
	}
```

3. Add field details to [apps/api/src/lib/services/serviceFields.ts](apps/api/src/lib/services/serviceFields.ts), so every component will know what to do with the values (decrypt/show it by default/readonly)

```js
export const umami = [{
	name: 'postgresqlUser',
	isEditable: false,
	isLowerCase: false,
	isNumber: false,
	isBoolean: false,
	isEncrypted: false
}]
```

4. Add service deletion query to `removeService` function in [apps/api/src/lib/services/common.ts](apps/api/src/lib/services/common.ts)


5. Add start process for the new service in [apps/api/src/routes/api/v1/services/handlers.ts](apps/api/src/routes/api/v1/services/handlers.ts)

> See startUmamiService() function as example.

6. Add the newly added start process to `startService` in [apps/api/src/routes/api/v1/services/handlers.ts](apps/api/src/routes/api/v1/services/handlers.ts)

7. You need to add a custom logo at [apps/ui/src/lib/components/svg/services](apps/ui/src/lib/components/svg/services) as a svelte component and export it in [apps/ui/src/lib/components/svg/services/index.ts](apps/ui/src/lib/components/svg/services/index.ts)

   SVG is recommended, but you can use PNG as well. It should have the `isAbsolute` variable with the suitable CSS classes, primarily for sizing and positioning.

8. You need to include it the logo at:

- [apps/ui/src/lib/components/svg/services/ServiceIcons.svelte](apps/ui/src/lib/components/svg/services/ServiceIcons.svelte) with `isAbsolute`.
- [apps/ui/src/routes/services/[id]/_ServiceLinks.svelte](apps/ui/src/routes/services/[id]/_ServiceLinks.svelte) with the link to the docs/main site of the service

9. By default the URL and the name frontend forms are included in [apps/ui/src/routes/services/[id]/_Services/_Services.svelte](apps/ui/src/routes/services/[id]/_Services/_Services.svelte).

   If you need to show more details on the frontend, such as users/passwords, you need to add Svelte component to [apps/ui/src/routes/services/[id]/_Services](apps/ui/src/routes/services/[id]/_Services) with an underscore. 
   
   > For example, see other [here](apps/ui/src/routes/services/[id]/_Services/_Umami.svelte).


Good job! 👏

<!-- # 🌐 Translate the project

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
3.  Have fun translating! -->
