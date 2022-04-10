import { asyncExecShell, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import {
	generateDatabaseConfiguration,
	getServiceImage,
	getVersions,
	ErrorHandler,
	getServiceImages
} from '$lib/database';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	try {
		const service = await db.getService({ id, teamId });
		const { destinationDockerId, destinationDocker, type, version, settings } = service;

		let isRunning = false;
		if (destinationDockerId) {
			const host = getEngine(destinationDocker.engine);
			const docker = dockerInstance({ destinationDocker });
			const baseImage = getServiceImage(type);
			const images = getServiceImages(type);
			docker.engine.pull(`${baseImage}:${version}`);
			if (images?.length > 0) {
				for (const image of images) {
					docker.engine.pull(`${image}:latest`);
				}
			}
			try {
				const { stdout } = await asyncExecShell(
					`DOCKER_HOST=${host} docker inspect --format '{{json .State}}' ${id}`
				);

				if (JSON.parse(stdout).Running) {
					isRunning = true;
				}
			} catch (error) {
				//
			}
		}
		return {
			body: {
				isRunning,
				service,
				settings
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
