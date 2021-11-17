import { asyncSleep } from '$lib/common';
import * as db from '$lib/database';
import { dayjs } from '$lib/dayjs';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    // TODO: Handle errors
    const buildId = request.query.get('buildId')
    const sequence = Number(request.query.get('sequence'))
    let logs = await db.prisma.buildLog.findMany({ where: { buildId, time: { gt: sequence } }, orderBy: { time: "asc" } })
    const { status } = await db.prisma.build.findUnique({ where: { id: buildId } })

    return {
        body: {
            logs,
            status
        }
    };

}