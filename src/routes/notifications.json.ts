import { ErrorHandler } from '$lib/database';
import { version } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
	try {
		const unreadNotifications = await db.prisma.notification.findMany({
			where: { isRead: false, showAtVersion: version }
		});
		return {
			status: 200,
			body: {
				...unreadNotifications
			}
		};
	} catch (error) {
		console.log(error);
		return ErrorHandler(error);
	}
};

export const post: RequestHandler = async (event) => {
	const { type, latestVersion } = await event.request.json();
	const settings = await db.prisma.setting.findFirst();

	return {
		status: 500
	};
};
