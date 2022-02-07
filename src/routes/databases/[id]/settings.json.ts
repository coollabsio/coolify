import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { generateDatabaseConfiguration, PrismaErrorHandler } from '$lib/database';
import { startTcpProxy, stopTcpHttpProxy } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { status, body, teamId } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	const { isPublic } = await event.request.json();

	try {
		await db.setDatabase({ id, isPublic });
		const database = await db.getDatabase({ id, teamId });
		const { destinationDockerId, destinationDocker, publicPort } = database;
		const { privatePort } = generateDatabaseConfiguration(database);

		if (destinationDockerId) {
			if (isPublic) {
				await startTcpProxy(destinationDocker, id, publicPort, privatePort);
			} else {
				await stopTcpHttpProxy(destinationDocker, publicPort);
			}
		}

		return {
			status: 201
		};
	} catch (error) {
		return PrismaErrorHandler(error);
	}
};
