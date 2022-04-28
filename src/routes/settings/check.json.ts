import { asyncExecShell, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { t } from '$lib/translations';
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
				error:
					found && t.get('application.domain_already_in_use', { domain: fqdn.replace('www.', '') })
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
