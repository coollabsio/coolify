import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { t } from '$lib/translations';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	const { debug, previews, dualCerts, autodeploy, branch, projectId } = await event.request.json();

	try {
		const isDouble = await db.checkDoubleBranch(branch, projectId);
		if (isDouble && autodeploy) {
			throw {
				message: t.get('application.cant_activate_auto_deploy_without_repo')
			};
		}
		await db.setApplicationSettings({ id, debug, previews, dualCerts, autodeploy });
		return { status: 201 };
	} catch (error) {
		return ErrorHandler(error);
	}
};
