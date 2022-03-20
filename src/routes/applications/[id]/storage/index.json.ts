import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';
import jsonwebtoken from 'jsonwebtoken';

export const get: RequestHandler = async (event) => {
	const { status, body, teamId } = await getUserDetails(event, false);
	if (status === 401) return { status, body };

	const { id } = event.params;
	try {
		const persistentStorages = await db.getPersistentStorage(id);
		return {
			body: {
				persistentStorages
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
	const { path } = await event.request.json();
	try {
		await db.prisma.applicationPersistentStorage.create({
			data: { path, application: { connect: { id } } }
		});
		return {
			status: 201
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};

export const del: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	const { path } = await event.request.json();

	try {
		await db.prisma.applicationPersistentStorage.deleteMany({ where: { applicationId: id, path } });
		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
