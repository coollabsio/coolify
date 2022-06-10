import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { getContainerUsage } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	let usage = {};
	try {
		const service = await db.getService({ id, teamId });
		if (service.destinationDockerId) {
			[usage] = await Promise.all([getContainerUsage(service.destinationDocker.engine, id)]);
		}
		return {
			status: 200,
			body: {
				usage
			},
			headers: {}
		};
	} catch (error) {
		console.log(error);
		return ErrorHandler(error);
	}
};
