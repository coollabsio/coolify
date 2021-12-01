import { selectTeam } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const teamId = selectTeam(request)
    const a = await db.prisma.application.findFirst({ where: { teams: { some: { teamId } } }, include: { gitSource: { include: { gitlabApp: true } } } })
    console.log(a)
const b = await db.prisma.team.findFirst({ where: { id: teamId }, include: {applications: true} })
console.log(b)

    return {
        body: {
            a,b
        }
    };
}
