import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { id } = event.params;
	try {
		return await db.getSshKey({ id });
	} catch (error) {
		return ErrorHandler(error);
	}
};
export const post: RequestHandler = async (event) => {
	const { id } = event.params;
	try {
		return await db.generateSshKey({ id });
	} catch (error) {
		return ErrorHandler(error);
	}
};
