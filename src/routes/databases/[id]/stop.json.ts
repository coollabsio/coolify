import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler, stopDatabase } from '$lib/database';
import { stopTcpHttpProxy } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals> = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	try {
		const database = await db.getDatabase({ id, teamId });
		const everStarted = await stopDatabase(database);
		if (everStarted) await stopTcpHttpProxy(database.destinationDocker, database.publicPort);
		await db.setDatabase({ id, isPublic: false });

		return {
			status: 200
		};
	} catch (error) {
		return PrismaErrorHandler(error);
	}
};
