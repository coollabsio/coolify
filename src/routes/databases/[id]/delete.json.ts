import { asyncExecShell, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { dockerInstance } from '$lib/docker';
import { deleteProxyForDatabase } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const del: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }
    const { id } = request.params
    try {
        const { destinationDockerId, destinationDocker } = await db.getDatabase({ id, teamId })
        await db.removeDatabase({ id })
        await deleteProxyForDatabase({ id })
        if (destinationDockerId) {
            const docker = dockerInstance({ destinationDocker })
            try {
                if (docker.engine.getContainer(id)) {
                    await docker.engine.getContainer(id).stop()
                    await docker.engine.getContainer(id).remove()
                }
            } catch (error) {
                console.log(error)
            }
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