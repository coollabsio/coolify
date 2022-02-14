import { getEngine, getUserDetails, removeDestinationDocker } from '$lib/common';
import { getDomain } from '$lib/components/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { dockerInstance } from '$lib/docker';
import { checkContainer, configureSimpleServiceProxyOff, stopTcpHttpProxy } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	try {
		const service = await db.getService({ id, teamId });
		const {
			destinationDockerId,
			destinationDocker,
			fqdn,
			minio: { publicPort }
		} = service;
		await db.updateMinioService({ id, publicPort: null });
		const domain = getDomain(fqdn);
		if (destinationDockerId) {
			const engine = destinationDocker.engine;

			try {
				const found = await checkContainer(engine, id);
				if (found) {
					await removeDestinationDocker({ id, engine });
				}
			} catch (error) {
				console.error(error);
			}
			await stopTcpHttpProxy(destinationDocker, publicPort);
			await configureSimpleServiceProxyOff({ domain });
		}

		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
