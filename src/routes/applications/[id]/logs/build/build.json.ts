import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const buildId = event.url.searchParams.get('buildId');
	const sequence = Number(event.url.searchParams.get('sequence'));
	try {
		let logs = await db.prisma.buildLog.findMany({
			where: { buildId, time: { gt: sequence } },
			orderBy: { time: 'asc' }
		});
		const data = await db.prisma.build.findFirst({ where: { id: buildId } });

		return {
			body: {
				logs,
				status: data?.status
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
