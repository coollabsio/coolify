import { asyncExecShell, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import {
	generateDatabaseConfiguration,
	getVersions,
	ErrorHandler,
	updatePasswordInDb
} from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	try {
		const database = await db.getDatabase({ id, teamId });
		const { destinationDockerId, destinationDocker } = database;

		let isRunning = false;
		if (destinationDockerId) {
			const host = getEngine(destinationDocker.engine);

			try {
				const { stdout } = await asyncExecShell(
					`DOCKER_HOST=${host} docker inspect --format '{{json .State}}' ${id}`
				);

				if (JSON.parse(stdout).Running) {
					isRunning = true;
				}
			} catch (error) {
				//
			}
		}
		const configuration = generateDatabaseConfiguration(database);
		const settings = await db.listSettings();
		return {
			body: {
				privatePort: configuration?.privatePort,
				database,
				isRunning,
				versions: getVersions(database.type),
				settings
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };
	const { id } = event.params;
	const {
		name,
		defaultDatabase,
		dbUser,
		dbUserPassword,
		rootUser,
		rootUserPassword,
		version,
		isRunning
	} = await event.request.json();

	try {
		const database = await db.getDatabase({ id, teamId });
		if (isRunning) {
			if (database.dbUserPassword !== dbUserPassword) {
				await updatePasswordInDb(database, dbUser, dbUserPassword, false);
			} else if (database.rootUserPassword !== rootUserPassword) {
				await updatePasswordInDb(database, rootUser, rootUserPassword, true);
			}
		}
		await db.updateDatabase({
			id,
			name,
			defaultDatabase,
			dbUser,
			dbUserPassword,
			rootUser,
			rootUserPassword,
			version
		});
		return { status: 201 };
	} catch (error) {
		return ErrorHandler(error);
	}
};
