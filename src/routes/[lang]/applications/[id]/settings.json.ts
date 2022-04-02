import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import { _ } from 'svelte-i18n';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	const { debug, previews, dualCerts, autodeploy, branch, projectId } = await event.request.json();

	try {
		const isDouble = await db.checkDoubleBranch(branch, projectId);
		if (isDouble && autodeploy) {
			throw {
				message: $_('application.app.error_double_app_for_one_branch')
			};
		}
		await db.setApplicationSettings({ id, debug, previews, dualCerts, autodeploy });
		return { status: 201 };
	} catch (error) {
		return ErrorHandler(error);
	}
};
