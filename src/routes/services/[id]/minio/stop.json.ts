import { getUserDetails } from '$lib/common';
import { getDomain } from '$lib/components/common';
import * as db from '$lib/database';
import { dockerInstance } from '$lib/docker';
import { configureSimpleServiceProxyOff, stopTcpHttpProxy } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params

    try {
        const service = await db.getService({ id, teamId })
        const { destinationDockerId, destinationDocker, fqdn, minio: { publicPort } } = service
        const domain = getDomain(fqdn)
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
            await stopTcpHttpProxy(destinationDocker, publicPort)
            await configureSimpleServiceProxyOff({ domain })
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