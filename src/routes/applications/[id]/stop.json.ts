import { getDomain, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { dockerInstance } from '$lib/docker';
import { removeProxyConfiguration } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	try {
		const { destinationDocker, destinationDockerId, fqdn } = await db.getApplication({
			id,
			teamId
		});
		const domain = getDomain(fqdn);
		if (destinationDockerId) {
			const docker = dockerInstance({ destinationDocker });
			await docker.engine.getContainer(id).stop();
		}
		await removeProxyConfiguration({ domain });
		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
