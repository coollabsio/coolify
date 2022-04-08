import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, userId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = await event.request.json();
	try {
		await db.prisma.user.update({ where: { id }, data: { password: 'RESETME' } });
		return {
			status: 201
		};
	} catch (error) {
		console.log(error);
		return {
			status: 500
		};
	}
};
