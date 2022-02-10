import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };
	const { id } = event.params;

	try {
		let { oauthId, groupName, appId, appSecret } = await event.request.json();

		oauthId = Number(oauthId);

		await db.addSource({ id, teamId, oauthId, groupName, appId, appSecret });
		return { status: 201 };
	} catch (error) {
		return PrismaErrorHandler(error);
	}
};
