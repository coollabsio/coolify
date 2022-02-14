import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const del: RequestHandler = async (events) => {
	const { teamId, status, body } = await getUserDetails(events);
	if (status === 401) return { status, body };

	const { id } = events.params;

	try {
		await db.removeService({ id });
		return { status: 200 };
	} catch (error) {
		return ErrorHandler(error);
	}
};
