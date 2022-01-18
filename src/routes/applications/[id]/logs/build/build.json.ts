import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const buildId = request.url.searchParams.get('buildId')
    const sequence = Number(request.url.searchParams.get('sequence'))
    try {
        let logs = await db.prisma.buildLog.findMany({ where: { buildId, time: { gt: sequence } }, orderBy: { time: "asc" } })
        const { status } = await db.prisma.build.findFirst({ where: { id: buildId } })

        return {
            body: {
                logs,
                status
            }
        };
    } catch (err) {
        return PrismaErrorHandler(err)
    }
}