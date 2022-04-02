import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = await event.request.json();

	try {
		const {
			destinationDockerId,
			destinationDocker,
			plausibleAnalytics: { postgresqlUser, postgresqlPassword, postgresqlDatabase }
		} = await db.getService({ id, teamId });
		if (destinationDockerId) {
			const docker = dockerInstance({ destinationDocker });
			const container = await docker.engine.getContainer(id);
			const command = await container.exec({
				Cmd: [
					`psql -H postgresql://${postgresqlUser}:${postgresqlPassword}@localhost:5432/${postgresqlDatabase} -c "UPDATE users SET email_verified = true;"`
				]
			});
			await command.start();
		}
		return { status: 201 };
	} catch (error) {
		return ErrorHandler(error);
	}
};
