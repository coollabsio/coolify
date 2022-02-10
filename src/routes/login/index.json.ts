import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { email, password } = await event.request.json();

	try {
		const { body } = await db.login({ email, password });
		event.locals.session.data = body;
		return {
			status: 200
		};
	} catch (error) {
		return PrismaErrorHandler(error);
	}
};

export const get: RequestHandler = async (event) => {
	const { userId } = await getUserDetails(event, false);
	if (!userId) {
		return {
			status: 401
		};
	}
	try {
		await db.getUser({ userId });
		return { status: 200 };
	} catch (error) {
		return PrismaErrorHandler(error);
	}
};
