import { asyncExecShell, getDomain, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	let { fqdn } = await event.request.json();

	if (fqdn) fqdn = fqdn.toLowerCase();

	try {
		const found = await db.isDomainConfigured({ id, fqdn });
		return {
			status: found ? 500 : 200,
			body: {
				error: found && `Domain ${getDomain(fqdn).replace('www.', '')} is already used.`
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
