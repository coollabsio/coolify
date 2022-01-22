import { getUserDetails } from '$lib/common';
import { getDomain } from '$lib/components/common';
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
        const { destinationDockerId, destinationDocker, fqdn } = service
        const domain = getDomain(fqdn)
        if (destinationDockerId) {
            const docker = dockerInstance({ destinationDocker })
            const wordpress = docker.engine.getContainer(id)
            const mysql = docker.engine.getContainer(`${id}-mysql`)

            try {
                if (wordpress) {
                    await wordpress.stop()
                    await wordpress.remove()
                }
            } catch (error) {
                console.error(error)
            }
            try {
                if (mysql) {
                    await mysql.stop()
                    await mysql.remove()
                }
            } catch (error) {
                console.error(error)
            }

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