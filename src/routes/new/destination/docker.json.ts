import { asyncExecShell, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const {
		name,
		engine,
		network,
		isCoolifyProxyUsed,
		remoteEngine,
		ipAddress,
		user,
		port,
		sshPrivateKey
	} = await event.request.json();

	try {
		let id = null;
		if (remoteEngine) {
			id = await db.newRemoteDestination({
				name,
				teamId,
				engine,
				network,
				isCoolifyProxyUsed,
				remoteEngine,
				ipAddress,
				user,
				port,
				sshPrivateKey
			});
		} else {
			id = await db.newLocalDestination({ name, teamId, engine, network, isCoolifyProxyUsed });
		}
		return { status: 200, body: { id } };
	} catch (error) {
		return ErrorHandler(error);
	}
};
