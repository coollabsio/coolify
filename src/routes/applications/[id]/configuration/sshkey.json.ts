import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals> = async (event) => {
	const { id } = event.params;
	try {
		return await db.generateSshKey({ id });
	} catch (error) {
		return PrismaErrorHandler(error);
	}
};
