import type { RequestHandler } from '@sveltejs/kit';
import * as db from '$lib/database';
import { t } from '$lib/translations';

export const get: RequestHandler = async () => {
	const users = await db.prisma.user.findMany({});
	return {
		status: 200,
		body: {
			users
		}
	};
};
export const post: RequestHandler = async (event) => {
	const { secretKey } = await event.request.json();
	if (secretKey !== process.env.COOLIFY_SECRET_KEY) {
		return {
			status: 500,
			body: {
				error: t.get('reset.invalid_secret_key')
			}
		};
	}
	return {
		status: 200
	};
};
