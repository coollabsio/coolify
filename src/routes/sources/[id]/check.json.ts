import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { t } from '$lib/translations';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };
	try {
		const { oauthId } = await event.request.json();
		const found = await db.prisma.gitlabApp.findFirst({ where: { oauthId: Number(oauthId) } });
		if (found) {
			throw {
				message: t.get('source.gitlab.already_configured')
			};
		}
		return { status: 200 };
	} catch (error) {
		return ErrorHandler(error);
	}
};
