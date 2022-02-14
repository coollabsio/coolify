import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import { ErrorHandler } from '$lib/database';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	try {
		const application = await db.getApplication({ id, teamId });
		return {
			status: 200,
			body: {
				type: application.gitSource.type,
				projectId: application.projectId,
				repository: application.repository,
				branch: application.branch,
				apiUrl: application.gitSource.apiUrl
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	const { buildPack } = await event.request.json();

	try {
		await db.configureBuildPack({ id, buildPack });
		return { status: 201 };
	} catch (error) {
		return ErrorHandler(error);
	}
};
