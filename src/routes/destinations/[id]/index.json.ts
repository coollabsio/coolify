import { asyncExecShell, getUserDetails } from '$lib/common';
import { generateRemoteEngine } from '$lib/components/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { checkContainer } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };
	console.log(teamId);
	const { id } = event.params;
	try {
		const destination = await db.getDestination({ id, teamId });
		const settings = await db.listSettings();
		let payload = {
			destination,
			settings,
			state: false
		};
		if (destination.remoteEngine) {
			// const { stdout } = await asyncExecShell(
			// 	`ssh -p ${destination.port} ${destination.user}@${destination.ipAddress} "docker ps -a"`
			// );
			// console.log(stdout)
			// const engine = await generateRemoteEngine(destination);
			// // await saveSshKey(destination);
			// payload.state = await checkContainer(engine, 'coolify-haproxy');
		} else {
			payload.state =
				destination?.engine && (await checkContainer(destination.engine, 'coolify-haproxy'));
		}
		return {
			status: 200,
			body: { ...payload }
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	const { name, engine, network } = await event.request.json();

	try {
		await db.updateDestination({ id, name, engine, network });
		return { status: 200 };
	} catch (error) {
		return ErrorHandler(error);
	}
};

export const del: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	try {
		await db.removeDestination({ id });
		return { status: 200 };
	} catch (error) {
		return ErrorHandler(error);
	}
};
