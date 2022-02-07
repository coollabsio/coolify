import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { name, type, htmlUrl, apiUrl, organization } = await event.request.json();
	try {
		const { id } = await db.newSource({ name, teamId, type, htmlUrl, apiUrl, organization });
		return { status: 201, body: { id } };
	} catch (e) {
		return PrismaErrorHandler(e);
	}
};
