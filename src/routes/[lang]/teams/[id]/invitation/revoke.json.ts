import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { dayjs } from '$lib/dayjs';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { userId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = await event.request.json();
	try {
		await db.prisma.teamInvitation.delete({ where: { id } });
		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
