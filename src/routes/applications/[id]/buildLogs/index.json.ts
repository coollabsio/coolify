import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const buildId = request.query.get('buildId')
    const { id } = request.params
    const builds = await db.prisma.build.findMany({ where: { applicationId: id }, orderBy: {createdAt: 'desc'} })
    if (buildId) {
        const build = await db.prisma.build.findUnique({ where: { id: buildId } })
        return {
            body: {
                builds,
                logs: await db.listLogs({ buildId }),
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