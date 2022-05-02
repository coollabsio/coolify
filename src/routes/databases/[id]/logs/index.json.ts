import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { dayjs } from '$lib/dayjs';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	let since = event.url.searchParams.get('since') || 0;
	if (since !== 0) {
		since = dayjs(since).unix();
	}
	try {
		const { destinationDockerId, destinationDocker } = await db.prisma.database.findUnique({
			where: { id },
			include: { destinationDocker: true }
		});
		if (destinationDockerId) {
			const docker = dockerInstance({ destinationDocker });
			try {
				const container = await docker.engine.getContainer(id);
				if (container) {
					const logs = (
						await container.logs({
							stdout: true,
							stderr: true,
							timestamps: true,
							since,
							tail: 5000
						})
					)
						.toString()
						.split('\n')
						.map((l) => l.slice(8))
						.filter((a) => a);
					return {
						body: {
							logs
						}
					};
				}
			} catch (error) {
				const { statusCode } = error;
				if (statusCode === 404) {
					return {
						body: {
							logs: []
						}
					};
				}
			}
		}
		return {
			status: 200,
			body: {
				message: 'No logs found.'
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
