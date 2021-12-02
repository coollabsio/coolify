import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const network = request.body.get('network')
    console.log(network)
    if (network) {
        const found = await db.prisma.destinationDocker.findFirst({ where: { network } })
        return {
            status: found ? 200 : 404,
        }
    }

    return {
        status: 401
    };
}