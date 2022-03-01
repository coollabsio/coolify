import { getUserDetails, removeDestinationDocker } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { checkContainer, stopTcpHttpProxy } from '$lib/haproxy';
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
			try {
				await stopTcpHttpProxy(destinationDocker, publicPort);
			} catch (error) {
				console.log(error);
			}
		}

		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
