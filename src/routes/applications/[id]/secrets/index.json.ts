import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	try {
		const secrets = await db.listSecrets({ applicationId: event.params.id });
		return {
			status: 200,
			body: {
				secrets: secrets.sort((a, b) => {
					return ('' + a.name).localeCompare(b.name);
				})
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	const { name, value, isBuildSecret } = await event.request.json();

	try {
		const found = await db.isSecretExists({ id, name });
		if (found) {
			throw {
				error: `Secret ${name} already exists.`
			};
		} else {
			await db.createSecret({ id, name, value, isBuildSecret });
			return {
				status: 201
			};
		}
	} catch (error) {
		return ErrorHandler(error);
	}
};
export const del: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	const { name } = await event.request.json();

	try {
		await db.removeSecret({ id, name });
		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
