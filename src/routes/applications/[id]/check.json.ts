import { asyncExecShell, getDomain, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals> = async (event) => {
	const { status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	let { fqdn } = await event.request.json();
	fqdn = fqdn.toLowerCase();

	try {
		const found = await db.isDomainConfigured({ id, fqdn });
		if (found) {
			throw {
				message: `Domain ${getDomain(fqdn)} is already configured`
			};
		}
		return {
			status: 200
		};
	} catch (error) {
		return PrismaErrorHandler(error);
	}
};
