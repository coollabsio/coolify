import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	try {
		const secrets = await db.listServiceSecrets(id);
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
	const { name, value, isBuildSecret, isPRMRSecret, isNew } = await event.request.json();
	try {
		if (isNew) {
			const found = await db.isServiceSecretExists({ id, name });
			if (found) {
				throw {
					error: `Secret ${name} already exists.`
				};
			} else {
				await db.createServiceSecret({ id, name, value });
				return {
					status: 201
				};
			}
		} else {
			await db.updateServiceSecret({ id, name, value });
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
		await db.removeServiceSecret({ id, name });
		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
