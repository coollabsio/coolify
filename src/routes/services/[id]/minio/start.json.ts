import { asyncExecShell, createDirectories, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { promises as fs } from 'fs';
import yaml from 'js-yaml';
import type { RequestHandler } from '@sveltejs/kit';
import { startHttpProxy } from '$lib/haproxy';
import getPort, { portNumbers } from 'get-port';
import { getDomain } from '$lib/components/common';
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
			fqdn,
			destinationDockerId,
			destinationDocker,
			minio: { rootUser, rootUserPassword },
			serviceSecret
		} = service;

		const data = await db.prisma.setting.findFirst();
		const { minPort, maxPort } = data;

		const network = destinationDockerId && destinationDocker.network;
		const host = getEngine(destinationDocker.engine);

		const publicPort = await getPort({ port: portNumbers(minPort, maxPort) });

		const consolePort = 9001;
		const apiPort = 9000;

		const { workdir } = await createDirectories({ repository: type, buildId: id });
		const image = getServiceImage(type);

		const config = {
			image: `${image}:${version}`,
			volume: `${id}-minio-data:/data`,
			environmentVariables: {
				MINIO_ROOT_USER: rootUser,
				MINIO_ROOT_PASSWORD: rootUserPassword,
				MINIO_BROWSER_REDIRECT_URL: fqdn
			}
		};
		if (serviceSecret.length > 0) {
			serviceSecret.forEach((secret) => {
				config.environmentVariables[secret.name] = secret.value;
			});
		}
		const composeFile: ComposeFile = {
			version: '3.8',
			services: {
				[id]: {
					container_name: id,
					image: config.image,
					command: `server /data --console-address ":${consolePort}"`,
					environment: config.environmentVariables,
					networks: [network],
					volumes: [config.volume],
					restart: 'always',
					labels: makeLabelForServices('minio')
				}
			},
			networks: {
				[network]: {
					external: true
				}
			},
			volumes: {
				[config.volume.split(':')[0]]: {
					name: config.volume.split(':')[0]
				}
			}
		};
		const composeFileDestination = `${workdir}/docker-compose.yaml`;
		await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

		try {
			await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
			await db.updateMinioService({ id, publicPort });
			await startHttpProxy(destinationDocker, id, publicPort, apiPort);
			return {
				status: 200
			};
		} catch (error) {
			console.log(error);
			return ErrorHandler(error);
		}
	} catch (error) {
		return ErrorHandler(error);
	}
};
