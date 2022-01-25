import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body }

	try {
		const applicationsCount = await (await db.listApplications(teamId)).length
		const sourcesCount = await (await db.listSources(teamId)).length
		const destinationsCount = await (await db.listDestinations(teamId)).length
		const teamsCount = await (await db.listTeams()).length
		const databasesCount = await (await db.listDatabases(teamId)).length
		const servicesCount = await (await db.listServices(teamId)).length
		return {
			body: {
				applicationsCount,
				sourcesCount,
				destinationsCount,
				teamsCount,
				databasesCount,
				servicesCount
			}
		};
	} catch (error) {
		return PrismaErrorHandler(error);
	}
}

export const post: RequestHandler<Locals> = async (event) => {
	const { status, body } = await getUserDetails(event, false);
	if (status === 401) return { status, body }

	const { cookie, value } = await event.request.json()
	const from = event.url.searchParams.get('from') || '/'

	return {
		status: 302,
		body: {},
		headers: {
			"set-cookie": [
				`${cookie}=${value}; HttpOnly; Path=/; Max-Age=15778800;`,
				"gitlabToken=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT"
			],
			Location: from
		}
	}
}