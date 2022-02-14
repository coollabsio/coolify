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
		const invitation = await db.prisma.teamInvitation.findFirst({
			where: { uid: userId },
			rejectOnNotFound: true
		});
		await db.prisma.team.update({
			where: { id: invitation.teamId },
			data: { users: { connect: { id: userId } } }
		});
		await db.prisma.permission.create({
			data: {
				user: { connect: { id: userId } },
				permission: invitation.permission,
				team: { connect: { id: invitation.teamId } }
			}
		});
		await db.prisma.teamInvitation.delete({ where: { id } });
		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
