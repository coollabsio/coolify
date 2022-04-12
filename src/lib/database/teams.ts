import type { Team, Permission } from '@prisma/client';
import { prisma } from './common';

export async function listTeams(): Promise<Team[]> {
	return await prisma.team.findMany();
}
export async function newTeam({ name, userId }: { name: string; userId: string }): Promise<Team> {
	return await prisma.team.create({
		data: {
			name,
			permissions: { create: { user: { connect: { id: userId } }, permission: 'owner' } },
			users: { connect: { id: userId } }
		}
	});
}
export async function getMyTeams({
	userId
}: {
	userId: string;
}): Promise<(Permission & { team: Team & { _count: { users: number } } })[]> {
	return await prisma.permission.findMany({
		where: { userId },
		include: { team: { include: { _count: { select: { users: true } } } } }
	});
}
