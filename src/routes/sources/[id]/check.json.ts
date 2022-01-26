import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals> = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };
	try {
		const { oauthId } = await event.request.json();
		await db.prisma.gitlabApp.findFirst({ where: { oauthId: Number(oauthId) } });
		return { status: 200 };
	} catch (error) {
		return PrismaErrorHandler(error);
	}
};
