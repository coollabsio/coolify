import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { configureDatabaseVisibility } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { status, body, teamId } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const isPublic = request.body.get('isPublic') === 'true' ? true : false

    try {
        await db.setDatabaseSettings({ id, isPublic })
        const { dbUser, dbUserPassword, domain, defaultDatabase, destinationDockerId, destinationDocker, port } = await db.getDatabase({ id, teamId })

        const url = `mysql://${dbUser}:${dbUserPassword}@${isPublic ? domain : id}:${port}/${defaultDatabase}`
        await db.updateDatabase({ id, url })
        if (destinationDockerId && destinationDocker.isCoolifyProxyUsed) {
            await configureDatabaseVisibility({ domain, isPublic })
        }
        return {
            status: 201
        }
    } catch (err) {
        return err
    }

}