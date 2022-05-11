import { asyncExecShell, getDomain, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { t } from '$lib/translations';
import type { RequestHandler } from '@sveltejs/kit';
import getPort from 'get-port';

export const post: RequestHandler = async (event) => {
	const { status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	let { fqdn, exposePort } = await event.request.json();

	if (fqdn) fqdn = fqdn.toLowerCase();

	try {
		const found = await db.isDomainConfigured({ id, fqdn });
		if (found) {
			throw {
				message: t.get('application.domain_already_in_use', {
					domain: getDomain(fqdn).replace('www.', '')
				})
			};
		}
		if (exposePort) {
			exposePort = Number(exposePort);

			if (exposePort < 1024 || exposePort > 65535) {
				throw { message: `Exposed Port needs to be between 1024 and 65535.` };
			}

			const publicPort = await getPort({ port: exposePort });
			if (publicPort !== exposePort) {
				throw { message: `Port ${exposePort} is already in use.` };
			}
		}
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
