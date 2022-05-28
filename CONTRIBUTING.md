# 👋 Welcome

First of all, thank you for considering contributing to my project! It means a lot 💜.

## 🙋 Want to help?

If you begin in GitHub contribution, you can find the [first contribution](https://github.com/firstcontributions/first-contributions) and follow this guide.

Follow the [introduction](#introduction) to get started then start contributing!

This is a little list of what you can do to help the project:

- [🧑‍💻 Develop your own ideas](#developer-contribution)
- [🌐 Translate the project](#translation)

## 👋 Introduction

> 🔴 At the moment, Coolify **doesn't support Windows**. You must use Linux or MacOS. 💡 Although windows users can use github codespaces for development

#### Recommended Pull Request Guideline

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
- **Docker Engine**

### Database migrations

During development, if you change the database layout, you need to run `pnpm db:push` to migrate the database and create types for Prisma. You also need to restart the development process.

If the schema is finalized, you need to create a migration file with `pnpm db:migrate <nameOfMigration>` where `nameOfMigration` is given by you. Make it sense. :)

### Tricky parts

- BullMQ, the queue system Coolify uses, cannot be hot reloaded. So if you change anything in the files related to it, you need to restart the development process. I'm actively looking for a different queue/scheduler library. I'm open to discussion!

---

# How to add new services

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

### Create Prisma / database schema for the new service.

You only need to do this if you store passwords or any persistent configuration. Mostly it is required by all services, but there are some exceptions, like NocoDB.

Update Prisma schema in [prisma/schema.prisma](prisma/schema.prisma).

- Add new model with the new service name.
- Make a relationshup with `Service` model.
- In the `Service` model, the name of the new field should be with low-capital.
- If the service needs a database, define a `publicPort` field to be able to make it's database public, example field name in case of PostgreSQL: `postgresqlPublicPort`. It should be a optional field.

If you are finished with the Prisma schema, you should update the database schema with `pnpm db:push` command.

> You must restart the running development environment to be able to use the new model

> If you use VSCode, you probably need to restart the `Typescript Language Server` to get the new types loaded in the running VSCode.

### Add supported versions

Supported versions are hardcoded into Coolify (for now).

You need to update `supportedServiceTypesAndVersions` function at [src/lib/components/common.ts](src/lib/components/common.ts). Example JSON:

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

### Update global functions

1. Add the new service to the `include` variable in [src/lib/database/services.ts](src/lib/database/services.ts), so it will be included in all places in the database queries where it is required.

```js
const include: Prisma.ServiceInclude = {
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

2. Update the database update query with the new service type to `configureServiceType` function in [src/lib/database/services.ts](src/lib/database/services.ts). This function defines the automatically generated variables (passwords, users, etc.) and it's encryption process (if applicable).

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

3. Add decryption process for configurations and passwords to `getService` function in [src/lib/database/services.ts](src/lib/database/services.ts)

```js
if (body.umami?.postgresqlPassword)
	body.umami.postgresqlPassword = decrypt(body.umami.postgresqlPassword);

if (body.umami?.hashSalt) body.umami.hashSalt = decrypt(body.umami.hashSalt);
```

4. Add service deletion query to `removeService` function in [src/lib/database/services.ts](src/lib/database/services.ts)

### Create API endpoints.

You need to add a new folder under [src/routes/services/[id]](src/routes/services/[id]) with the low-capital name of the service. You need 3 default files in that folder.

#### `index.json.ts`:

It has a POST endpoint that updates the service details in Coolify's database, such as name, url, other configurations, like passwords. It should look something like this:

```js
import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	let { name, fqdn } = await event.request.json();
	if (fqdn) fqdn = fqdn.toLowerCase();

	try {
		await db.updateService({ id, fqdn, name });
		return { status: 201 };
	} catch (error) {
		return ErrorHandler(error);
	}
};
```

If it's necessary, you can create your own database update function, specifically for the new service.

#### `start.json.ts`

It has a POST endpoint that sets all the required secrets, persistent volumes, `docker-compose.yaml` file and sends a request to the specified docker engine.

You could also define an `HTTP` or `TCP` proxy for every other port that should be proxied to your server. (See `startHttpProxy` and `startTcpProxy` functions in [src/lib/haproxy/index.ts](src/lib/haproxy/index.ts))

#### `stop.json.ts`

It has a POST endpoint that stops the service and all dependent (TCP/HTTP proxies) containers. If publicPort is specified it also needs to cleanup it from the database.

## Frontend

1. You need to add a custom logo at [src/lib/components/svg/services/](src/lib/components/svg/services/) as a svelte component.

   SVG is recommended, but you can use PNG as well. It should have the `isAbsolute` variable with the suitable CSS classes, primarily for sizing and positioning.

2. You need to include it the logo at

- [src/routes/services/index.svelte](src/routes/services/index.svelte) with `isAbsolute` in two places,
- [src/lib/components/ServiceLinks.svelte](src/lib/components/ServiceLinks.svelte) with `isAbsolute` and a link to the docs/main site of the service
- [src/routes/services/[id]/configuration/type.svelte](src/routes/services/[id]/configuration/type.svelte) with `isAbsolute`.

3. By default the URL and the name frontend forms are included in [src/routes/services/[id]/\_Services/\_Services.svelte](src/routes/services/[id]/_Services/_Services.svelte).

   If you need to show more details on the frontend, such as users/passwords, you need to add Svelte component to [src/routes/services/[id]/\_Services](src/routes/services/[id]/_Services) with an underscore. For example, see other files in that folder.

   You also need to add the new inputs to the `index.json.ts` file of the specific service, like for MinIO here: [src/routes/services/[id]/minio/index.json.ts](src/routes/services/[id]/minio/index.json.ts)

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
