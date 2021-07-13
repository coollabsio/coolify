import type { Request } from '@sveltejs/kit';
import yaml from 'js-yaml';
import generator from 'generate-password';
import { promises as fs } from 'fs';
import { docker } from '$lib/api/docker';
import { baseServiceConfiguration } from '$lib/api/applications/common';
import { cleanupTmp, execShellAsync } from '$lib/api/common';

export async function post(request: Request) {
	let { baseURL, remoteDB, database, wordpressExtraConfiguration } = request.body;
	const traefikURL = baseURL;
	baseURL = `https://${baseURL}`;
	const workdir = '/tmp/wordpress';
	const deployId = `wp-${generator.generate({ length: 5, numbers: true, strict: true })}`;
	const defaultDatabaseName = generator.generate({ length: 12, numbers: true, strict: true });
	const defaultDatabaseHost = `${deployId}-mysql`;
	const defaultDatabaseUser = generator.generate({ length: 12, numbers: true, strict: true });
	const defaultDatabasePassword = generator.generate({ length: 24, numbers: true, strict: true });
	const defaultDatabaseRootPassword = generator.generate({
		length: 24,
		numbers: true,
		strict: true
	});
	const defaultDatabaseRootUser = generator.generate({ length: 12, numbers: true, strict: true });
	let secrets = [
		{ name: 'WORDPRESS_DB_HOST', value: defaultDatabaseHost },
		{ name: 'WORDPRESS_DB_USER', value: defaultDatabaseUser },
		{ name: 'WORDPRESS_DB_PASSWORD', value: defaultDatabasePassword },
		{ name: 'WORDPRESS_DB_NAME', value: defaultDatabaseName },
		{ name: 'WORDPRESS_CONFIG_EXTRA', value: wordpressExtraConfiguration }
	];

	const generateEnvsMySQL = {
		MYSQL_ROOT_PASSWORD: defaultDatabaseRootPassword,
		MYSQL_ROOT_USER: defaultDatabaseRootUser,
		MYSQL_USER: defaultDatabaseUser,
		MYSQL_PASSWORD: defaultDatabasePassword,
		MYSQL_DATABASE: defaultDatabaseName
	};
	const image = 'bitnami/mysql:8.0';
	const volume = `${deployId}-mysql-data:/bitnami/mysql/data`;

	if (remoteDB) {
		secrets = [
			{ name: 'WORDPRESS_DB_HOST', value: database.host },
			{ name: 'WORDPRESS_DB_USER', value: database.user },
			{ name: 'WORDPRESS_DB_PASSWORD', value: database.password },
			{ name: 'WORDPRESS_DB_NAME', value: database.name },
			{ name: 'WORDPRESS_TABLE_PREFIX', value: database.tablePrefix },
			{ name: 'WORDPRESS_CONFIG_EXTRA', value: wordpressExtraConfiguration }
		];
	}

	const generateEnvsWordpress = {};
	for (const secret of secrets) generateEnvsWordpress[secret.name] = secret.value;
	let stack = {
		version: '3.8',
		services: {
			[deployId]: {
				image: 'wordpress',
				networks: [`${docker.network}`],
				environment: generateEnvsWordpress,
				volumes: [`${deployId}-wordpress-data:/var/www/html`],
				deploy: {
					...baseServiceConfiguration,
					labels: [
						'managedBy=coolify',
						'type=service',
						'serviceName=' + deployId,
						'configuration=' +
							JSON.stringify({
								deployId,
								baseURL,
								generateEnvsWordpress
							}),
						'traefik.enable=true',
						'traefik.http.services.' + deployId + '.loadbalancer.server.port=80',
						'traefik.http.routers.' + deployId + '.entrypoints=websecure',
						'traefik.http.routers.' +
							deployId +
							'.rule=Host(`' +
							traefikURL +
							'`) && PathPrefix(`/`)',
						'traefik.http.routers.' + deployId + '.tls.certresolver=letsencrypt',
						'traefik.http.routers.' + deployId + '.middlewares=global-compress'
					]
				}
			},
			[`${deployId}-mysql`]: {
				image,
				networks: [`${docker.network}`],
				environment: generateEnvsMySQL,
				volumes: [volume],
				deploy: {
					...baseServiceConfiguration,
					labels: ['managedBy=coolify', 'type=service', 'serviceName=' + deployId]
				}
			}
		},
		networks: {
			[`${docker.network}`]: {
				external: true
			}
		},
		volumes: {
			[`${deployId}-wordpress-data`]: {
				external: true
			},
			[`${deployId}-mysql-data`]: {
				external: true
			}
		}
	};
	if (remoteDB) {
		stack = {
			version: '3.8',
			services: {
				[deployId]: {
					image: 'wordpress',
					networks: [`${docker.network}`],
					environment: generateEnvsWordpress,
					volumes: [`${deployId}-wordpress-data:/var/www/html`],
					deploy: {
						...baseServiceConfiguration,
						labels: [
							'managedBy=coolify',
							'type=service',
							'serviceName=' + deployId,
							'configuration=' +
								JSON.stringify({
									deployId,
									baseURL,
									generateEnvsWordpress
								}),
							'traefik.enable=true',
							'traefik.http.services.' + deployId + '.loadbalancer.server.port=80',
							'traefik.http.routers.' + deployId + '.entrypoints=websecure',
							'traefik.http.routers.' +
								deployId +
								'.rule=Host(`' +
								traefikURL +
								'`) && PathPrefix(`/`)',
							'traefik.http.routers.' + deployId + '.tls.certresolver=letsencrypt',
							'traefik.http.routers.' + deployId + '.middlewares=global-compress'
						]
					}
				}
			},
			networks: {
				[`${docker.network}`]: {
					external: true
				}
			},
			volumes: {
				[`${deployId}-wordpress-data`]: {
					external: true
				}
			}
		};
	}
	await execShellAsync(`mkdir -p ${workdir}`);
	await fs.writeFile(`${workdir}/stack.yml`, yaml.dump(stack));
	await execShellAsync(`docker stack rm ${deployId}`);
	await execShellAsync(`cat ${workdir}/stack.yml | docker stack deploy --prune -c - ${deployId}`);
	cleanupTmp(workdir);
	return {
		status: 200,
		body: { message: 'OK' }
	};
}
