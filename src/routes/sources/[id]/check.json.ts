import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const oauthId = request.body.get('oauthId')
    if (oauthId) {
        const found = await db.prisma.gitlabApp.findFirst({ where: { oauthId: Number(oauthId) } })
        return {
            status: found ? 200 : 404,
        }
    }

    return {
        status: 401
    };
}