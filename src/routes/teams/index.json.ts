import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const { userId, status, body, permission } = await getUserDetails(request, false);
    if (status === 401) return { status, body }

    try {
        const teams = await db.prisma.permission.findMany({ where: { userId }, include: { team: { include: { _count: { select: { users: true } } } } } })
        const invitations = await db.prisma.teamInvitation.findMany({ where: { uid: userId } })
        return {
            body: {
                teams,
                invitations
            }
        };
    } catch (err) {
        console.log(err)
    }

}