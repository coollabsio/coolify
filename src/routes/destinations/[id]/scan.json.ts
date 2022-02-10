import { asyncExecShell, getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
	const { teamId, status, body } = await getUserDetails(request);
	if (status === 401) return { status, body };

	const { id } = request.params;

	const destinationDocker = await db.getDestination({ id, teamId });
	const docker = dockerInstance({ destinationDocker });
	const listContainers = await docker.engine.listContainers({
		filters: { network: [destinationDocker.network] }
	});
	const containers = listContainers.filter((container) => {
		return container.Labels['coolify.configuration'];
	});
	const jsonContainers = containers
		.map((container) =>
			JSON.parse(Buffer.from(container.Labels['coolify.configuration'], 'base64').toString())
		)
		.filter((container) => container.type === 'manual');
	return {
		body: {
			containers: jsonContainers
		}
	};
};

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	let { fqdn, projectId, repository, branch } = await event.request.json();
	if (fqdn) fqdn = fqdn.toLowerCase();
	if (projectId) projectId = Number(projectId);

	try {
		const foundByDomain = await db.prisma.application.findFirst({ where: { fqdn } });
		const foundByRepository = await db.prisma.application.findFirst({
			where: { repository, branch, projectId }
		});
		if (foundByDomain) {
			return {
				status: 200,
				body: { by: 'domain', name: foundByDomain.name }
			};
		}
		if (foundByRepository) {
			return {
				status: 200,
				body: { by: 'repository', name: foundByRepository.name }
			};
		}
		return {
			status: 404
		};
	} catch (error) {
		return PrismaErrorHandler(error);
	}
};
