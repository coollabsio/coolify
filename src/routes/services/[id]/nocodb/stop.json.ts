import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { dockerInstance } from '$lib/docker';
import { configureSimpleServiceProxyOff } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params

    try {
        const service = await db.getService({ id, teamId })
        const { destinationDockerId, destinationDocker, domain } = service
        if (destinationDockerId) {
            const docker = dockerInstance({ destinationDocker })
            const container = docker.engine.getContainer(id)
          
            try {
                if (container) {
                    await container.stop()
                    await container.remove()
                }
            } catch (error) {
                console.error(error)
            }
           
            await configureSimpleServiceProxyOff({ domain:domain.replace(/^https?:\/\//, '').replace(/^http?:\/\//, '') })
        }

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