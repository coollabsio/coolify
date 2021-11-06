import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const { id } = request.params
    const builds = await db.prisma.build.findMany({ where: { applicationId: id }, orderBy: { createdAt: 'desc' } })

    return {
        body: {
            builds
        }
    };
}