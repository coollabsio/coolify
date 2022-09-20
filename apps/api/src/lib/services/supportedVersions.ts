/*
 Example of a supported version:
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
*/
export const supportedServiceTypesAndVersions = [
	{
		name: 'plausibleanalytics',
		fancyName: 'Plausible Analytics',
		baseImage: 'plausible/analytics',
		images: ['bitnami/postgresql:13.2.0', 'yandex/clickhouse-server:21.3.2.5'],
		versions: ['latest', 'stable'],
		recommendedVersion: 'stable',
		ports: {
			main: 8000
		}
	},
	{
		name: 'nocodb',
		fancyName: 'NocoDB',
		baseImage: 'nocodb/nocodb',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 8080
		}
	},
	{
		name: 'minio',
		fancyName: 'MinIO',
		baseImage: 'minio/minio',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 9001
		}
	},
	{
		name: 'vscodeserver',
		fancyName: 'VSCode Server',
		baseImage: 'codercom/code-server',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 8080
		}
	},
	{
		name: 'wordpress',
		fancyName: 'Wordpress',
		baseImage: 'wordpress',
		images: ['bitnami/mysql:5.7'],
		versions: ['latest', 'php8.1', 'php8.0', 'php7.4', 'php7.3'],
		recommendedVersion: 'latest',
		ports: {
			main: 80
		}
	},
	{
		name: 'vaultwarden',
		fancyName: 'Vaultwarden',
		baseImage: 'vaultwarden/server',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 80
		}
	},
	{
		name: 'languagetool',
		fancyName: 'LanguageTool',
		baseImage: 'silviof/docker-languagetool',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 8010
		}
	},
	{
		name: 'n8n',
		fancyName: 'n8n',
		baseImage: 'n8nio/n8n',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 5678
		}
	},
	{
		name: 'uptimekuma',
		fancyName: 'Uptime Kuma',
		baseImage: 'louislam/uptime-kuma',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 3001
		}
	},
	{
		name: 'ghost',
		fancyName: 'Ghost',
		baseImage: 'bitnami/ghost',
		images: ['bitnami/mariadb'],
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 2368
		}
	},
	{
		name: 'meilisearch',
		fancyName: 'Meilisearch',
		baseImage: 'getmeili/meilisearch',
		images: [],
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 7700
		}
	},
	{
		name: 'umami',
		fancyName: 'Umami',
		baseImage: 'ghcr.io/umami-software/umami',
		images: ['postgres:12-alpine'],
		versions: ['postgresql-latest'],
		recommendedVersion: 'postgresql-latest',
		ports: {
			main: 3000
		}
	},
	{
		name: 'hasura',
		fancyName: 'Hasura',
		baseImage: 'hasura/graphql-engine',
		images: ['postgres:12-alpine'],
		versions: ['latest', 'v2.10.0', 'v2.5.1'],
		recommendedVersion: 'v2.10.0',
		ports: {
			main: 8080
		}
	},
	{
		name: 'fider',
		fancyName: 'Fider',
		baseImage: 'getfider/fider',
		images: ['postgres:12-alpine'],
		versions: ['stable'],
		recommendedVersion: 'stable',
		ports: {
			main: 3000
		}
	},
	{
		name: 'appwrite',
		fancyName: 'Appwrite',
		baseImage: 'appwrite/appwrite',
		images: ['mariadb:10.7', 'redis:6.2-alpine', 'appwrite/telegraf:1.4.0'],
		versions: ['latest', '1.0','0.15.3'],
		recommendedVersion: '0.15.3',
		ports: {
			main: 80
		}
	},
	// {
	//     name: 'moodle',
	//     fancyName: 'Moodle',
	//     baseImage: 'bitnami/moodle',
	//     images: [],
	//     versions: ['latest', 'v4.0.2'],
	//     recommendedVersion: 'latest',
	//     ports: {
	//         main: 8080
	//     }
	// }
	{
		name: 'glitchTip',
		fancyName: 'GlitchTip',
		baseImage: 'glitchtip/glitchtip',
		images: ['postgres:14-alpine', 'redis:7-alpine'],
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 8000
		}
	},
	{
		name: 'searxng',
		fancyName: 'SearXNG',
		baseImage: 'searxng/searxng',
		images: [],
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 8080
		}
	},
	{
		name: 'weblate',
		fancyName: 'Weblate',
		baseImage: 'weblate/weblate',
		images: ['postgres:14-alpine', 'redis:6-alpine'],
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 8080
		}
	},
	// {
	// 	name: 'taiga',
	// 	fancyName: 'Taiga',
	// 	baseImage: 'taigaio/taiga-front',
	// 	images: ['postgres:12.3', 'rabbitmq:3.8-management-alpine', 'taigaio/taiga-back', 'taigaio/taiga-events', 'taigaio/taiga-protected'],
	// 	versions: ['latest'],
	// 	recommendedVersion: 'latest',
	// 	ports: {
	// 		main: 80
	// 	}
	// },
	{
		name: 'trilium',
		fancyName: 'Trilium Notes',
		baseImage: 'zadam/trilium',
		images: [],
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 8080
		}
	},
];