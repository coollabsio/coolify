import { getUserDetails, removeDestinationDocker } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { checkContainer } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	try {
		const service = await db.getService({ id, teamId });
		const {
			destinationDockerId,
			destinationDocker,
			fqdn,
			wordpress: { ftpEnabled }
		} = service;
		if (destinationDockerId) {
			const engine = destinationDocker.engine;
			try {
				const found = await checkContainer(engine, id);
				if (found) {
					await removeDestinationDocker({ id, engine });
				}
			} catch (error) {
				console.error(error);
			}
			try {
				const found = await checkContainer(engine, `${id}-mysql`);
				if (found) {
					await removeDestinationDocker({ id: `${id}-mysql`, engine });
				}
			} catch (error) {
				console.error(error);
			}
			try {
				if (ftpEnabled) {
					const found = await checkContainer(engine, `${id}-ftp`);
					if (found) {
						await removeDestinationDocker({ id: `${id}-ftp`, engine });
					}
					await db.prisma.wordpress.update({
						where: { serviceId: id },
						data: { ftpEnabled: false }
					});
				}
			} catch (error) {
				console.error(error);
			}
		}

		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
