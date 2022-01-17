import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
	try {
		const teamId = getTeam(request)
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
	} catch (err) {
		return err
	}
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
	const { status, body } = await getUserDetails(request, false);
	if (status === 401) return { status, body }

	const cookie = request.body.get('cookie')
	const value = request.body.get('value')
	const from = request.url.searchParams.get('from') || '/'

	return {
		status: 302,
		headers: {
			"set-cookie": [
				`${cookie}=${value}; HttpOnly; Path=/; Max-Age=15778800;`,
				"gitlabToken=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT"
			],
			Location: from
		}
	}
}