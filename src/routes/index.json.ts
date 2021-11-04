import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async () => {
	return {
		body: {
			applicationsCount: await (await db.listApplications()).length,
			sourcesCount: await (await db.listSources()).length,
			destinationsCount: await (await db.listDestinations()).length
		}
	};
}
