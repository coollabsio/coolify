import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler, stopDatabase } from '$lib/database';
import { deleteProxy } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const del: RequestHandler<Locals> = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };
	const { id } = event.params;
	try {
		const database = await db.getDatabase({ id, teamId });
		if (database.destinationDockerId) {
			const everStarted = await stopDatabase(database);
			console.log(everStarted);
			if (everStarted) await deleteProxy({ id });
		}
		await db.removeDatabase({ id });
		return { status: 200 };
	} catch (error) {
		console.log(error);
		return PrismaErrorHandler(error);
	}
};
