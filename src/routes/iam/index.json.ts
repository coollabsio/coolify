import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const { teamId, userId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	try {
		const account = await db.prisma.user.findUnique({
			where: { id: userId },
			select: { id: true, email: true, teams: true }
		});
		let accounts = [];
		if (teamId === '0') {
			accounts = await db.prisma.user.findMany({ select: { id: true, email: true, teams: true } });
		}

		const teams = await db.prisma.permission.findMany({
			where: { userId: teamId === '0' ? undefined : userId },
			include: { team: { include: { _count: { select: { users: true } } } } }
		});

		const invitations = await db.prisma.teamInvitation.findMany({ where: { uid: userId } });
		return {
			status: 200,
			body: {
				teams,
				invitations,
				account,
				accounts
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};

export const post: RequestHandler = async (event) => {
	const { teamId, userId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };
	if (teamId !== '0')
		return { status: 401, body: { message: 'You are not authorized to perform this action' } };

	const { id } = await event.request.json();
	try {
		const aloneInTeams = await db.prisma.team.findMany({ where: { users: { every: { id } } } });
		if (aloneInTeams.length > 0) {
			for (const team of aloneInTeams) {
				const applications = await db.prisma.application.findMany({
					where: { teams: { every: { id: team.id } } }
				});
				if (applications.length > 0) {
					for (const application of applications) {
						await db.prisma.application.update({
							where: { id: application.id },
							data: { teams: { connect: { id: '0' } } }
						});
					}
				}
				const services = await db.prisma.service.findMany({
					where: { teams: { every: { id: team.id } } }
				});
				if (services.length > 0) {
					for (const service of services) {
						await db.prisma.service.update({
							where: { id: service.id },
							data: { teams: { connect: { id: '0' } } }
						});
					}
				}
				const databases = await db.prisma.database.findMany({
					where: { teams: { every: { id: team.id } } }
				});
				if (databases.length > 0) {
					for (const database of databases) {
						await db.prisma.database.update({
							where: { id: database.id },
							data: { teams: { connect: { id: '0' } } }
						});
					}
				}
				const sources = await db.prisma.gitSource.findMany({
					where: { teams: { every: { id: team.id } } }
				});
				if (sources.length > 0) {
					for (const source of sources) {
						await db.prisma.gitSource.update({
							where: { id: source.id },
							data: { teams: { connect: { id: '0' } } }
						});
					}
				}
				const destinations = await db.prisma.destinationDocker.findMany({
					where: { teams: { every: { id: team.id } } }
				});
				if (destinations.length > 0) {
					for (const destination of destinations) {
						await db.prisma.destinationDocker.update({
							where: { id: destination.id },
							data: { teams: { connect: { id: '0' } } }
						});
					}
				}
				await db.prisma.teamInvitation.deleteMany({ where: { teamId: team.id } });
				await db.prisma.permission.deleteMany({ where: { teamId: team.id } });
				await db.prisma.user.delete({ where: { id } });
				await db.prisma.team.delete({ where: { id: team.id } });
			}
		}

		const notAloneInTeams = await db.prisma.team.findMany({ where: { users: { some: { id } } } });
		if (notAloneInTeams.length > 0) {
			for (const team of notAloneInTeams) {
				await db.prisma.team.update({
					where: { id: team.id },
					data: { users: { disconnect: { id } } }
				});
			}
		}
		return {
			status: 201
		};
	} catch (error) {
		return {
			status: 500
		};
	}
};
