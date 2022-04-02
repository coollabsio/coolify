import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
	const { teamId, status, body } = await getUserDetails(request);
	if (status === 401) return { status, body };

	try {
		return {
			body: {
				destinations: await db.listDestinations(teamId)
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
