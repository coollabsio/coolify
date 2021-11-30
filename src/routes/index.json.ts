import { selectTeam } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
	const teamId = selectTeam(request)
	const applicationsCount = await (await db.listApplications(teamId)).length
	const sourcesCount = await (await db.listSources(teamId)).length
	const destinationsCount = await (await db.listDestinations(teamId)).length
	
	return {
		body: {
			applicationsCount,
			sourcesCount,
			destinationsCount
		}
	};
}
