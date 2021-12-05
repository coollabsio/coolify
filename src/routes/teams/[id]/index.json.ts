import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const { userId, status, body } = await getUserDetails(request, false);
    if (status === 401) return { status, body }
    try {
        const user = await db.prisma.user.findFirst({ where: { id: userId, teams: { some: { id: request.params.id } } }, include: { permission: true } })
        if (!user) {
            return {
                status: 401
            }
        }
        const permissions = await db.prisma.permission.findMany({ where: { teamId: request.params.id }, include: { user: { select: { id: true, email: true } } } })
        const team = await db.prisma.team.findUnique({ where: { id: request.params.id }, include: { permissions: true } })
        const invitations = await db.prisma.teamInvitation.findMany({ where: { teamId: team.id } })
        return {
            body: {
                team,
                permissions,
                invitations
            }
        };
    } catch (err) {
        return PrismaErrorHandler(err);
    }
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const name = request.body.get('name')
    
    try {
        await db.prisma.team.update({ where: { id }, data: { name: { set: name } } })
        return {
            status: 200
        }
    } catch (err) {
        return PrismaErrorHandler(err);
    }

}