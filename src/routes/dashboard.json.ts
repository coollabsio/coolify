import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import os from 'node:os';
import osu from 'node-os-utils';

export const get: RequestHandler = async (event) => {
	const { userId, teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };
	const usage = event.url.searchParams.get('usage');
	if (usage) {
		try {
			return {
				status: 200,
				body: {
					uptime: os.uptime(),
					memory: await osu.mem.info(),
					cpu: {
						load: os.loadavg(),
						usage: await osu.cpu.usage(),
						count: os.cpus().length
					},
					disk: await osu.drive.info()
				}
			};
		} catch (error) {
			return ErrorHandler(error);
		}
	} else {
		try {
			const applicationsCount = await db.prisma.application.count({
				where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } }
			});
			const sourcesCount = await db.prisma.gitSource.count({
				where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } }
			});
			const destinationsCount = await db.prisma.destinationDocker.count({
				where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } }
			});
			const teamsCount = await db.prisma.permission.count({ where: { userId } });
			const databasesCount = await db.prisma.database.count({
				where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } }
			});
			const servicesCount = await db.prisma.service.count({
				where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } }
			});
			const teams = await db.prisma.permission.findMany({
				where: { userId },
				include: { team: { include: { _count: { select: { users: true } } } } }
			});
			const settings = await db.prisma.setting.findFirst();
			return {
				body: {
					teams,
					applicationsCount,
					sourcesCount,
					destinationsCount,
					teamsCount,
					databasesCount,
					servicesCount,
					settings
				}
			};
		} catch (error) {
			return ErrorHandler(error);
		}
	}
};

export const post: RequestHandler = async (event) => {
	const { status, body } = await getUserDetails(event, false);
	if (status === 401) return { status, body };

	const { cookie, value } = await event.request.json();
	const from = event.url.searchParams.get('from') || '/';

	return {
		status: 302,
		body: {},
		headers: {
			'set-cookie': [
				`${cookie}=${value}; HttpOnly; Path=/; Max-Age=15778800;`,
				'gitlabToken=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT',
				'githubToken=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT'
			],
			Location: from
		}
	};
};
