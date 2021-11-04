import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const buildId = request.query.get('buildId')
    const last = Number(request.query.get('last'))
    const { id } = request.params
    const builds = await db.prisma.build.findMany({ where: { applicationId: id }, orderBy: { createdAt: 'desc' } })
    if (buildId) {
        const build = await db.prisma.build.findUnique({ where: { id: buildId } })
        let logs = []
        if (last) {
            logs = await db.listLogs({ buildId, last })
        } else {
            logs = await db.listLogs({ buildId })
        }
        return {
            body: {
                builds,
                logs,
                status: build.status
            }
        };


    }
    return {
        body: {
            logs: [],
            builds
        }
    };
}