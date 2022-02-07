import { asyncExecShell, getEngine, getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import { checkContainer } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	try {
		const destination = await db.getDestination({ id, teamId });
		const settings = await db.listSettings();
		const state = await checkContainer(destination.engine, 'coolify-haproxy');
		return {
			status: 200,
			body: {
				destination,
				settings,
				state
			}
		};
	} catch (error) {
		return PrismaErrorHandler(error);
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
		return PrismaErrorHandler(error);
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
		return PrismaErrorHandler(error);
	}
};
