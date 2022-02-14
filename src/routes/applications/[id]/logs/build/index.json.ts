import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { dayjs } from '$lib/dayjs';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { id } = event.params;
	const buildId = event.url.searchParams.get('buildId');
	const skip = Number(event.url.searchParams.get('skip')) || 0;

	let builds = [];
	try {
		const buildCount = await db.prisma.build.count({ where: { applicationId: id } });
		if (buildId) {
			builds = await db.prisma.build.findMany({ where: { applicationId: id, id: buildId } });
		} else {
			builds = await db.prisma.build.findMany({
				where: { applicationId: id },
				orderBy: { createdAt: 'desc' },
				take: 5,
				skip
			});
		}
		builds = builds.map((build) => {
			const updatedAt = dayjs(build.updatedAt).utc();
			build.took = updatedAt.diff(dayjs(build.createdAt)) / 1000;
			build.since = updatedAt.fromNow();
			return build;
		});
		return {
			status: 200,
			body: {
				builds,
				buildCount
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
