import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { dayjs } from '$lib/dayjs';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { userId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const teamId = request.body.get('teamId')
    const uid = request.body.get('uid')

    await db.prisma.team.update({ where: { id: teamId }, data: { users: { disconnect: { id: uid } } } })
    await db.prisma.permission.deleteMany({ where: { userId: uid, teamId } })
    return {
        status: 200
    }
}