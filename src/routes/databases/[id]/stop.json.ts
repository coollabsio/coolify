import { asyncExecShell, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { deleteProxyForDatabase } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params

    try {
        const database = await db.getDatabase({ id, teamId })
        const { destinationDockerId, destinationDocker } = database
        if (destinationDockerId) {
            const host = getEngine(destinationDocker.engine)
            await asyncExecShell(`DOCKER_HOST=${host} docker stop -t 0 ${id} && docker rm ${id}`)
            await db.setDatabase({ id, url: null }) 
            await deleteProxyForDatabase({ id })
        }

        return {
            status: 200
        }
    } catch (err) {
        return err
    }

}