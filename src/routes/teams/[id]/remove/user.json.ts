import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { userId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { teamId, uid } = await event.request.json();

	try {
		await db.prisma.team.update({
			where: { id: teamId },
			data: { users: { disconnect: { id: uid } } }
		});
		await db.prisma.permission.deleteMany({ where: { userId: uid, teamId } });
		return {
			status: 201
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
