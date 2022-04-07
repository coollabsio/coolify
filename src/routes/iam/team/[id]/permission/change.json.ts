import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { userId, newPermission, permissionId } = await event.request.json();

	try {
		await db.prisma.permission.updateMany({
			where: { id: permissionId, userId },
			data: { permission: { set: newPermission } }
		});
		return {
			status: 201
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
