import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals> = async (event) => {
	const { status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { engine, isCoolifyProxyUsed } = await event.request.json();

	try {
		await db.setDestinationSettings({ engine, isCoolifyProxyUsed });
		return { status: 200 };
	} catch (error) {
		return PrismaErrorHandler(error);
	}
};
