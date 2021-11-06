import { asyncSleep } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const { id } = request.params
    const buildId = request.query.get('buildId')
    const sequence = Number(request.query.get('sequence'))
    const logs = await db.prisma.buildLog.findMany({ where: { buildId, time: { gt: sequence } }, orderBy: { time: "asc" } })
    const { status } = await db.prisma.build.findUnique({ where: { id: buildId } })
    return {
        body: {
            logs,
            status
        }
    };

}