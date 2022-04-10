import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { teamId, userId, status, body } = await getUserDetails(event, false);
	if (status === 401) return { status, body };

	const { id } = event.params;

	try {
		const user = await db.prisma.user.findFirst({
			where: { id: userId, teams: teamId === '0' ? undefined : { some: { id } } },
			include: { permission: true }
		});
		if (!user) {
			return {
				status: 401
			};
		}
		const permissions = await db.prisma.permission.findMany({
			where: { teamId: id },
			include: { user: { select: { id: true, email: true } } }
		});
		const team = await db.prisma.team.findUnique({ where: { id }, include: { permissions: true } });
		const invitations = await db.prisma.teamInvitation.findMany({ where: { teamId: team.id } });
		return {
			body: {
				team,
				permissions,
				invitations
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};

export const post: RequestHandler = async (event) => {
	const { status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	const { name } = await event.request.json();

	try {
		await db.prisma.team.update({ where: { id }, data: { name: { set: name } } });
		return {
			status: 201
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
