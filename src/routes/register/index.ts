import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async () => {
	try {
		return { status: 200, body: { userCount: await db.prisma.user.count() } };
	} catch (error) {
		return ErrorHandler(error);
	}
};
