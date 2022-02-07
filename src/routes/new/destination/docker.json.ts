import { asyncExecShell, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { name, engine, network, isCoolifyProxyUsed } = await event.request.json();

	try {
		const id = await db.newDestination({ name, teamId, engine, network, isCoolifyProxyUsed });
		return { status: 200, body: { id } };
	} catch (error) {
		return PrismaErrorHandler(error);
	}
};
