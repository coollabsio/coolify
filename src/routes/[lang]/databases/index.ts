import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	try {
		const databases = await db.listDatabases(teamId);
		return {
			status: 200,
			body: {
				databases
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
