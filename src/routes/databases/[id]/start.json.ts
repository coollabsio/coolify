import { asyncExecShell, createDirectories, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { generateDatabaseConfiguration, ErrorHandler } from '$lib/database';
import { promises as fs } from 'fs';
import yaml from 'js-yaml';
import type { RequestHandler } from '@sveltejs/kit';
import { makeLabelForStandaloneDatabase } from '$lib/buildPacks/common';
import { startTcpProxy } from '$lib/haproxy';
import type { ComposeFile } from '$lib/types/composeFile';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	try {
		const database = await db.getDatabase({ id, teamId });
		const {
			type,
			destinationDockerId,
			destinationDocker,
			publicPort,
			settings: { isPublic }
		} = database;
		const { privatePort, environmentVariables, image, volume, ulimits } =
			generateDatabaseConfiguration(database);

		const network = destinationDockerId && destinationDocker.network;
		const host = getEngine(destinationDocker.engine);
		const engine = destinationDocker.engine;
		const volumeName = volume.split(':')[0];
		const labels = await makeLabelForStandaloneDatabase({ id, image, volume });

		const { workdir } = await createDirectories({ repository: type, buildId: id });

		const composeFile: ComposeFile = {
			version: '3.8',
			services: {
				[id]: {
					container_name: id,
					image,
					networks: [network],
					environment: environmentVariables,
					volumes: [volume],
					ulimits,
					labels,
					restart: 'always'
				}
			},
			networks: {
				[network]: {
					external: true
				}
			},
			volumes: {
				[volumeName]: {
					external: true
				}
			}
		};
		const composeFileDestination = `${workdir}/docker-compose.yaml`;
		await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
		try {
			await asyncExecShell(`DOCKER_HOST=${host} docker volume create ${volumeName}`);
		} catch (error) {
			console.log(error);
		}
		try {
			await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
			if (isPublic) await startTcpProxy(destinationDocker, id, publicPort, privatePort);
			return {
				status: 200
			};
		} catch (error) {
			throw {
				error
			};
		}
	} catch (error) {
		return ErrorHandler(error);
	}
};
