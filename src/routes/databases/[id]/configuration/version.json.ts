import { getUserDetails } from '$lib/common';
import { supportedDatabaseTypesAndVersions } from '$lib/components/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	const { type } = await db.getDatabase({ id, teamId });

	return {
		status: 200,
		body: {
			versions: supportedDatabaseTypesAndVersions.find((name) => name.name === type).versions
		}
	};
};

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	const { version } = await event.request.json();

	try {
		await db.setDatabase({ id, version });
		return {
			status: 201
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
