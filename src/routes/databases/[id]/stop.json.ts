import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { stopDatabase } from '$lib/database';
import { stopTcpHttpProxy } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params

    try {
        const database = await db.getDatabase({ id, teamId })
        const everStarted = await stopDatabase(database)
        if (everStarted) await stopTcpHttpProxy(database.destinationDocker, database.publicPort)
        await db.setDatabase({ id, isPublic: false })

        return {
            status: 200
        }
    } catch (err) {
        return {
            status: 500,
            body: {
                message: err.message || err
            }
        }
    }

}