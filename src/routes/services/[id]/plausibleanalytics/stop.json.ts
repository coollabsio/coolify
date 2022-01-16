import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { stopDatabase, stopService } from '$lib/database';
import { dockerInstance } from '$lib/docker';
import { stopDatabaseProxy } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params

    try {
        const service = await db.getService({ id, teamId })
        const { destinationDockerId, destinationDocker } = service
        if (destinationDockerId) {
            const docker = dockerInstance({ destinationDocker })
            const container = docker.engine.getContainer(id)
            const postgresqlContainer = docker.engine.getContainer(`${id}-postgresql`)
            const clickhouseContainer = docker.engine.getContainer(`${id}-clickhouse`)
            try {
                if (container) {
                    await container.stop()
                    await container.remove()
                }
            } catch (error) {
                console.error(error)
            }
            try {
                if (postgresqlContainer) {
                    await postgresqlContainer.stop()
                    await postgresqlContainer.remove()
                }
            } catch (error) {
                console.error(error)
            }
            try {
                if (postgresqlContainer) {
                    await clickhouseContainer.stop()
                    await clickhouseContainer.remove()
                }
            } catch (error) {
                console.error(error)
            }
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