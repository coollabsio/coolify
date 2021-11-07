import * as db from '$lib/database';
import { dayjs } from '$lib/dayjs';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const { id } = request.params
    const buildId = request.query.get('buildId')
    const skip = Number(request.query.get('skip')) || 0
    let builds = []
    const buildCount = await db.prisma.build.count({where: { applicationId: id }})
    if (buildId) {
        builds = await db.prisma.build.findMany({ where: { applicationId: id, id: buildId } })
    } else {
        builds = await db.prisma.build.findMany({ where: { applicationId: id }, orderBy: { createdAt: 'desc' }, take: 5, skip })
        
    }
    builds = builds.map(build => {
        const updatedAt = dayjs(build.updatedAt).utc();
        build.took = updatedAt.diff(dayjs(build.createdAt)) / 1000;
        build.since = updatedAt.fromNow();
        return build
    })
    return {
        body: {
            builds,
            buildCount
        }
    };
}