import { getUserDetails, uniqueName } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { userId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { name } = await event.request.json();

	try {
		const { id } = await db.newTeam({ name, userId });
		return { status: 201, body: { id } };
	} catch (error) {
		return ErrorHandler(error);
	}
};
