import {
	asyncExecShell,
	createDirectories,
	getDomain,
	getEngine,
	getUserDetails
} from '$lib/common';
import * as db from '$lib/database';
import { promises as fs } from 'fs';
import yaml from 'js-yaml';
import type { RequestHandler } from '@sveltejs/kit';
import { ErrorHandler, getServiceImage } from '$lib/database';
import { makeLabelForServices } from '$lib/buildPacks/common';
import type { ComposeFile } from '$lib/types/composeFile';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	try {
		const service = await db.getService({ id, teamId });
		const {
			type,
			version,
			destinationDockerId,
			destinationDocker,
			serviceSecret,
			fqdn,
			ghost: {
				defaultEmail,
				defaultPassword,
				mariadbRootUser,
				mariadbRootUserPassword,
				mariadbDatabase,
				mariadbPassword,
				mariadbUser
			}
		} = service;
		const network = destinationDockerId && destinationDocker.network;
		const host = getEngine(destinationDocker.engine);

		const { workdir } = await createDirectories({ repository: type, buildId: id });
		const image = getServiceImage(type);
		const domain = getDomain(fqdn);
		const isHttps = fqdn.startsWith('https://');
		const config = {
			ghost: {
				image: `${image}:${version}`,
				volume: `${id}-ghost:/bitnami/ghost`,
				environmentVariables: {
					url: fqdn,
					GHOST_HOST: domain,
					GHOST_ENABLE_HTTPS: isHttps ? 'yes' : 'no',
					GHOST_EMAIL: defaultEmail,
					GHOST_PASSWORD: defaultPassword,
					GHOST_DATABASE_HOST: `${id}-mariadb`,
					GHOST_DATABASE_USER: mariadbUser,
					GHOST_DATABASE_PASSWORD: mariadbPassword,
					GHOST_DATABASE_NAME: mariadbDatabase,
					GHOST_DATABASE_PORT_NUMBER: 3306
				}
			},
			mariadb: {
				image: `bitnami/mariadb:latest`,
				volume: `${id}-mariadb:/bitnami/mariadb`,
				environmentVariables: {
					MARIADB_USER: mariadbUser,
					MARIADB_PASSWORD: mariadbPassword,
					MARIADB_DATABASE: mariadbDatabase,
					MARIADB_ROOT_USER: mariadbRootUser,
					MARIADB_ROOT_PASSWORD: mariadbRootUserPassword
				}
			}
		};
		if (serviceSecret.length > 0) {
			serviceSecret.forEach((secret) => {
				config.ghost.environmentVariables[secret.name] = secret.value;
			});
		}
		const composeFile: ComposeFile = {
			version: '3.8',
			services: {
				[id]: {
					container_name: id,
					image: config.ghost.image,
					networks: [network],
					volumes: [config.ghost.volume],
					environment: config.ghost.environmentVariables,
					restart: 'always',
					labels: makeLabelForServices('ghost'),
					depends_on: [`${id}-mariadb`]
				},
				[`${id}-mariadb`]: {
					container_name: `${id}-mariadb`,
					image: config.mariadb.image,
					networks: [network],
					volumes: [config.mariadb.volume],
					environment: config.mariadb.environmentVariables,
					restart: 'always'
				}
			},
			networks: {
				[network]: {
					external: true
				}
			},
			volumes: {
				[config.ghost.volume.split(':')[0]]: {
					name: config.ghost.volume.split(':')[0]
				},
				[config.mariadb.volume.split(':')[0]]: {
					name: config.mariadb.volume.split(':')[0]
				}
			}
		};
		const composeFileDestination = `${workdir}/docker-compose.yaml`;
		await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

		try {
			if (version === 'latest') {
				await asyncExecShell(
					`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`
				);
			}
			await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
			return {
				status: 200
			};
		} catch (error) {
			return ErrorHandler(error);
		}
	} catch (error) {
		return ErrorHandler(error);
	}
};
