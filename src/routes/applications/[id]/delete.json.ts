import { selectTeam } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import type { Locals } from 'src/global';

export const del: RequestHandler<Locals, FormData> = async (request) => {
    const teamId = selectTeam(request)
    const { id } = request.params
    try {
        await db.prisma.application.delete({ where: { id, teamId } })
        await db.prisma.buildLog.deleteMany({ where: { applicationId: id } })
        return {
            status: 200
        }
    } catch (error) {
        console.error(error)
        return {
            status: 500
        }
    }

}