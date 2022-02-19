import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';
import jsonwebtoken from 'jsonwebtoken';

export const get: RequestHandler = async (event) => {
	const { status, body, teamId } = await getUserDetails(event, false);
	if (status === 401) return { status, body };

	const { id } = event.params;
	try {
		const secrets = await db.listSecrets(id);
		const applicationSecrets = secrets.filter((secret) => !secret.isPRMRSecret);
		const PRMRSecrets = secrets.filter((secret) => secret.isPRMRSecret);
		const destinationDocker = await db.getDestinationByApplicationId({ id, teamId });
		const docker = dockerInstance({ destinationDocker });
		const listContainers = await docker.engine.listContainers({
			filters: { network: [destinationDocker.network] }
		});
		const containers = listContainers.filter((container) => {
			return (
				container.Labels['coolify.configuration'] &&
				container.Labels['coolify.type'] === 'standalone-application'
			);
		});
		const jsonContainers = containers
			.map((container) =>
				JSON.parse(Buffer.from(container.Labels['coolify.configuration'], 'base64').toString())
			)
			.filter((container) => {
				return (
					container.type !== 'manual' &&
					container.type !== 'webhook_commit' &&
					container.applicationId === id
				);
			});
		return {
			body: {
				containers: jsonContainers,
				applicationSecrets: applicationSecrets.sort((a, b) => {
					return ('' + a.name).localeCompare(b.name);
				}),
				PRMRSecrets: PRMRSecrets.sort((a, b) => {
					return ('' + a.name).localeCompare(b.name);
				})
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
