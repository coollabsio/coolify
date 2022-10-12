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
		},
		labels: ['analytics', 'plausible', 'plausible-analytics', 'gdpr', 'no-cookie']
	},
	{
		name: 'nocodb',
		fancyName: 'NocoDB',
		baseImage: 'nocodb/nocodb',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 8080
		},
		labels: ['nocodb', 'airtable', 'database']
	},
	{
		name: 'minio',
		fancyName: 'MinIO',
		baseImage: 'minio/minio',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 9001
		},
		labels: ['minio', 's3', 'storage']
	},
	{
		name: 'vscodeserver',
		fancyName: 'VSCode Server',
		baseImage: 'codercom/code-server',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 8080
		},
		labels: ['vscodeserver', 'vscode', 'code-server', 'ide']
	},
	{
		name: 'wordpress',
		fancyName: 'WordPress',
		baseImage: 'wordpress',
		images: ['bitnami/mysql:5.7'],
		versions: ['latest', 'php8.1', 'php8.0', 'php7.4', 'php7.3'],
		recommendedVersion: 'latest',
		ports: {
			main: 80
		},
		labels: ['wordpress', 'blog', 'cms']
	},
	{
		name: 'vaultwarden',
		fancyName: 'Vaultwarden',
		baseImage: 'vaultwarden/server',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 80
		},
		labels: ['vaultwarden', 'password-manager', 'passwords']
	},
	{
		name: 'languagetool',
		fancyName: 'LanguageTool',
		baseImage: 'silviof/docker-languagetool',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 8010
		},
		labels: ['languagetool', 'grammar', 'spell-checker']
	},
	{
		name: 'n8n',
		fancyName: 'n8n',
		baseImage: 'n8nio/n8n',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 5678
		},
		labels: ['n8n', 'workflow', 'automation', 'ifttt', 'zapier', 'nodered']
	},
	{
		name: 'uptimekuma',
		fancyName: 'Uptime Kuma',
		baseImage: 'louislam/uptime-kuma',
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 3001
		},
		labels: ['uptimekuma', 'uptime', 'monitoring']
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
		},
		labels: ['ghost', 'blog', 'cms']
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
		},
		labels: ['meilisearch', 'search', 'search-engine']
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
		},
		labels: ['umami', 'analytics', 'gdpr', 'no-cookie']
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
		},
		labels: ['hasura', 'graphql', 'database']
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
		},
		labels: ['fider', 'feedback', 'suggestions']
	},
	{
		name: 'appwrite',
		fancyName: 'Appwrite',
		baseImage: 'appwrite/appwrite',
		images: ['mariadb:10.7', 'redis:6.2-alpine', 'appwrite/telegraf:1.4.0'],
		versions: ['latest', '1.0', '0.15.3'],
		recommendedVersion: '1.0',
		ports: {
			main: 80
		},
		labels: ['appwrite', 'database', 'storage', 'api', 'serverless']
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
		},
		labels: ['glitchtip', 'error-reporting', 'error', 'sentry', 'bugsnag']
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
		},
		labels: ['searxng', 'search', 'search-engine']
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
		},
		labels: ['weblate', 'translation', 'localization']
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
		name: 'grafana',
		fancyName: 'Grafana',
		baseImage: 'grafana/grafana',
		images: [],
		versions: ['latest', '9.1.3', '9.1.2', '9.0.8', '8.3.11', '8.4.11', '8.5.11'],
		recommendedVersion: 'latest',
		ports: {
			main: 3000
		},
		labels: ['grafana', 'monitoring', 'metrics', 'dashboard']
	},
	{
		name: 'trilium',
		fancyName: 'Trilium Notes',
		baseImage: 'zadam/trilium',
		images: [],
		versions: ['latest'],
		recommendedVersion: 'latest',
		ports: {
			main: 8080
		},
		labels: ['trilium', 'notes', 'note-taking', 'wiki']
	},
];
