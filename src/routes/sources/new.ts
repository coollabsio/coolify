import { getUserDetails, uniqueName } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const name = uniqueName();
	try {
		const { id } = await db.newSource({ teamId, name });
		return { status: 201, body: { id } };
	} catch (e) {
		return ErrorHandler(e);
	}
};
