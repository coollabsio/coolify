import type { RequestHandler } from '@sveltejs/kit';
import * as db from '$lib/database';

export const post: RequestHandler = async (event) => {
	const data = await event.request.json();
	for (const d of data) {
		if (d.container_name) {
			const { log, container_name: containerId, source } = d;
			console.log(log);
			// await db.prisma.applicationLogs.create({ data: { log, containerId: containerId.substr(1), source } });
		}
	}

	return {
		status: 200,
		body: {}
	};
};
