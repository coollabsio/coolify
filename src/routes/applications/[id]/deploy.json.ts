import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import cuid from 'cuid';
import crypto from 'crypto';
import { buildQueue } from '$lib/queues';
import { getUserDetails } from '$lib/common';
import { ErrorHandler } from '$lib/database';

export const post: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	try {
		const buildId = cuid();
		const applicationFound = await db.getApplication({ id, teamId });
		if (!applicationFound.configHash) {
			const configHash = crypto
				.createHash('sha256')
				.update(
					JSON.stringify({
						buildPack: applicationFound.buildPack,
						port: applicationFound.port,
						installCommand: applicationFound.installCommand,
						buildCommand: applicationFound.buildCommand,
						startCommand: applicationFound.startCommand
					})
				)
				.digest('hex');
			await db.prisma.application.update({ where: { id }, data: { configHash } });
		}
		await buildQueue.add(buildId, { build_id: buildId, type: 'manual', ...applicationFound });
		return {
			status: 200,
			body: {
				buildId
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
