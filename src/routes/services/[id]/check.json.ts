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
	let { fqdn, exposePort, otherFqdns } = await event.request.json();

	if (fqdn) fqdn = fqdn.toLowerCase();
	if (otherFqdns) otherFqdns = otherFqdns.map((fqdn) => fqdn.toLowerCase());
	if (exposePort) exposePort = Number(exposePort);

	try {
		let found = await db.isDomainConfigured({ id, fqdn });
		if (found) {
			throw {
				message: t.get('application.domain_already_in_use', {
					domain: getDomain(fqdn).replace('www.', '')
				})
			};
		}
		if (otherFqdns) {
			for (const ofqdn of otherFqdns) {
				const domain = getDomain(ofqdn);
				const nakedDomain = domain.replace('www.', '');
				found = await db.isDomainConfigured({ id, fqdn: ofqdn, checkOwn: true });
				if (found) {
					throw {
						message: t.get('application.domain_already_in_use', {
							domain: nakedDomain
						})
					};
				}
			}
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
