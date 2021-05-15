import { saveServerLog } from '$lib/api/applications/logging';
import { docker } from '$lib/api/docker';
import type { Request } from '@sveltejs/kit';
import yaml from 'js-yaml';
import { promises as fs } from 'fs';
import cuid from 'cuid';
import generator from 'generate-password';
import { uniqueNamesGenerator, adjectives, colors, animals } from 'unique-names-generator';
import { execShellAsync } from '$lib/api/common';

function getUniq() {
	return uniqueNamesGenerator({ dictionaries: [adjectives, animals, colors], length: 2 });
}

export async function post(request: Request) {
	try {
		const { type } = request.body;
		let { defaultDatabaseName } = request.body;
		const passwords = generator.generateMultiple(2, {
			length: 24,
			numbers: true,
			strict: true
		});
		const usernames = generator.generateMultiple(2, {
			length: 10,
			numbers: true,
			strict: true
		});
		// TODO: Query for existing db with the same name
		const nickname = getUniq();

		if (!defaultDatabaseName) defaultDatabaseName = nickname;

		const deployId = cuid();
		const configuration = {
			general: {
				workdir: `/tmp/${deployId}`,
				deployId,
				nickname,
				type
			},
			database: {
				usernames,
				passwords,
				defaultDatabaseName
			},
			deploy: {
				name: nickname
			}
		};
		await execShellAsync(`mkdir -p ${configuration.general.workdir}`);
		let generateEnvs = {};
		let image = null;
		let volume = null;
		let ulimits = {};
		if (type === 'mongodb') {
			generateEnvs = {
				MONGODB_ROOT_PASSWORD: passwords[0],
				MONGODB_USERNAME: usernames[0],
				MONGODB_PASSWORD: passwords[1],
				MONGODB_DATABASE: defaultDatabaseName
			};
			image = 'bitnami/mongodb:4.4';
			volume = `${configuration.general.deployId}-${type}-data:/bitnami/mongodb`;
		} else if (type === 'postgresql') {
			generateEnvs = {
				POSTGRESQL_PASSWORD: passwords[0],
				POSTGRESQL_USERNAME: usernames[0],
				POSTGRESQL_DATABASE: defaultDatabaseName
			};
			image = 'bitnami/postgresql:13.2.0';
			volume = `${configuration.general.deployId}-${type}-data:/bitnami/postgresql`;
		} else if (type === 'couchdb') {
			generateEnvs = {
				COUCHDB_PASSWORD: passwords[0],
				COUCHDB_USER: usernames[0]
			};
			image = 'bitnami/couchdb:3';
			volume = `${configuration.general.deployId}-${type}-data:/bitnami/couchdb`;
		} else if (type === 'mysql') {
			generateEnvs = {
				MYSQL_ROOT_PASSWORD: passwords[0],
				MYSQL_ROOT_USER: usernames[0],
				MYSQL_USER: usernames[1],
				MYSQL_PASSWORD: passwords[1],
				MYSQL_DATABASE: defaultDatabaseName
			};
			image = 'bitnami/mysql:8.0';
			volume = `${configuration.general.deployId}-${type}-data:/bitnami/mysql/data`;
		} else if (type === 'clickhouse') {
			image = 'yandex/clickhouse-server';
			volume = `${configuration.general.deployId}-${type}-data:/var/lib/clickhouse`;
			ulimits = {
				nofile: {
					soft: 262144,
					hard: 262144
				}
			};
		} else if (type === 'redis') {
			image = 'bitnami/redis';
			volume = `${configuration.general.deployId}-${type}-data:/bitnami/redis/data`;
			generateEnvs = {
				REDIS_PASSWORD: passwords[0]
			};
		}

		const stack = {
			version: '3.8',
			services: {
				[configuration.general.deployId]: {
					image,
					networks: [`${docker.network}`],
					environment: generateEnvs,
					volumes: [volume],
					ulimits,
					deploy: {
						replicas: 1,
						update_config: {
							parallelism: 0,
							delay: '10s',
							order: 'start-first'
						},
						rollback_config: {
							parallelism: 0,
							delay: '10s',
							order: 'start-first'
						},
						labels: [
							'managedBy=coolify',
							'type=database',
							'configuration=' + JSON.stringify(configuration)
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
				[`${configuration.general.deployId}-${type}-data`]: {
					external: true
				}
			}
		};
		await fs.writeFile(`${configuration.general.workdir}/stack.yml`, yaml.dump(stack));
		await execShellAsync(
			`cat ${configuration.general.workdir}/stack.yml | docker stack deploy -c - ${configuration.general.deployId}`
		);
		return {
			status: 201,
			body: {
				message: 'Deployed.'
			}
		};
	} catch (error) {
		console.log(error);
		await saveServerLog(error);
		return {
			status: 500,
			body: {
				error
			}
		};
	}
}
