import { asyncExecShell, getEngine, getUserDetails, removeDestinationDocker } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { checkContainer } from '$lib/haproxy';
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
		if (destinationDockerId) {
			const { engine } = destinationDocker;
			const found = await checkContainer(engine, id);
			if (found) {
				await removeDestinationDocker({ id, engine });
			}
		}
		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
