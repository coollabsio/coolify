import { asyncExecShell, getHost, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { deleteProxyForDatabase } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const del: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }
    const { id } = request.params
    try {
        const { domain, destinationDockerId, destinationDocker } = await db.getDatabase({ id, teamId })
        await db.removeDatabase({ id })
        await deleteProxyForDatabase({ domain })
        if (destinationDockerId) {
            const host = getHost({ engine: destinationDocker.engine })
            await asyncExecShell(`DOCKER_HOST="${host}" docker stop -t 0 ${id} && docker rm ${id}`)
        }
        return {
            status: 200
        }
    } catch (error) {
        console.error(error)
        return {
            status: 500
        }
    }

}