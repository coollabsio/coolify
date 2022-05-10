import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };
	const { id } = event.params;

	let {
		name,
		fqdn,
		exposePort,
		wordpress: { extraConfig, mysqlDatabase, mysqlHost, mysqlPort }
	} = await event.request.json();

	if (fqdn) fqdn = fqdn.toLowerCase();
	if (exposePort) exposePort = Number(exposePort);
	if (mysqlPort) mysqlPort = Number(mysqlPort);

	try {
		await db.updateWordpress({
			id,
			fqdn,
			name,
			extraConfig,
			mysqlDatabase,
			exposePort,
			mysqlHost,
			mysqlPort
		});
		return { status: 201 };
	} catch (error) {
		return ErrorHandler(error);
	}
};
