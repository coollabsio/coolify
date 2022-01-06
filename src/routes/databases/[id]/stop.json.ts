import { asyncExecShell, getHost, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params

    try {
        const database = await db.getDatabase({ id, teamId })
        const { name, domain, dbUser, dbUserPassword, rootUser, rootUserPassword, defaultDatabase, version, type, destinationDockerId, destinationDocker } = database
        if (destinationDockerId) {
            const host = getHost({ engine: destinationDocker.engine })

            await asyncExecShell(`DOCKER_HOST=${host} docker stop -t 0 ${id} && docker rm ${id}`)
            await db.updateDatabase({ id, name, domain, defaultDatabase, dbUser, dbUserPassword, rootUser, rootUserPassword, version, url: null })
        }

        return {
            status: 200
        }
    } catch (err) {
        return err
    }

}