import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const del: RequestHandler<Locals, FormData> = async (request) => {
    const { id } = request.params
    await db.prisma.application.delete({ where: { id } })
    await db.prisma.buildLog.deleteMany({ where: { applicationId: id } })
    return {
        status: 200
    }
}