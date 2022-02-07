import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	let { name, fqdn, port, buildCommand, startCommand, installCommand } = await event.request.json();

	if (fqdn) fqdn = fqdn.toLowerCase();
	if (port) port = Number(port);

	try {
		const { id } = await db.importApplication({
			name,
			teamId,
			fqdn,
			port,
			buildCommand,
			startCommand,
			installCommand
		});
		return { status: 201, body: { id } };
	} catch (error) {
		return PrismaErrorHandler(error);
	}
};
