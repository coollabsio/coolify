import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { generateDatabaseConfiguration, ErrorHandler } from '$lib/database';
import { startTcpProxy, stopTcpHttpProxy } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';
import getPort, { portNumbers } from 'get-port';

export const post: RequestHandler = async (event) => {
	const { status, body, teamId } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	const data = await db.prisma.setting.findFirst();
	const { minPort, maxPort } = data;

	const { isPublic, appendOnly = true } = await event.request.json();
	const publicPort = await getPort({ port: portNumbers(minPort, maxPort) });

	try {
		await db.setDatabase({ id, isPublic, appendOnly });
		const database = await db.getDatabase({ id, teamId });
		const { destinationDockerId, destinationDocker, publicPort: oldPublicPort } = database;
		const { privatePort } = generateDatabaseConfiguration(database);

		if (destinationDockerId) {
			if (isPublic) {
				await db.prisma.database.update({ where: { id }, data: { publicPort } });
				await startTcpProxy(destinationDocker, id, publicPort, privatePort);
			} else {
				await db.prisma.database.update({ where: { id }, data: { publicPort: null } });
				await stopTcpHttpProxy(destinationDocker, oldPublicPort);
			}
		}
		return {
			status: 201,
			body: {
				publicPort
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
