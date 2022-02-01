import { prisma, PrismaErrorHandler } from './common';

export async function listTeams() {
	return await prisma.team.findMany();
}
export async function newTeam({ name, userId }) {
	return await prisma.team.create({
		data: {
			name,
			permissions: { create: { user: { connect: { id: userId } }, permission: 'owner' } },
			users: { connect: { id: userId } }
		}
	});
}
export async function getMyTeams({ userId }) {
	return await prisma.permission.findMany({
		where: { userId },
		include: { team: { include: { _count: { select: { users: true } } } } }
	});
}
