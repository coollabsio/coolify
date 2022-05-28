import { asyncExecShell, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { checkContainer, getContainerUsage, isContainerExited } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';
import { setDefaultConfiguration } from '$lib/buildPacks/common';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	let isRunning = false;
	let isExited = false;
	try {
		const application = await db.getApplication({ id, teamId });
		if (application.destinationDockerId) {
			[isRunning, isExited] = await Promise.all([
				checkContainer(application.destinationDocker.engine, id),
				isContainerExited(application.destinationDocker.engine, id)
			]);
		}
		return {
			status: 200,
			body: {
				isRunning,
				isExited
			},
			headers: {}
		};
	} catch (error) {
		console.log(error);
		return ErrorHandler(error);
	}
};
