import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { configureProxyForApplication, configureProxyForDatabase } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { status, body, teamId } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const isPublic = request.body.get('isPublic') === 'true' ? true : false

    try {
        await db.setDatabaseSettings({ id, isPublic })
        const { dbUser, dbUserPassword, domain, defaultDatabase, destinationDockerId, destinationDocker } = await db.getDatabase({ id, teamId })

        let url = `mysql://${dbUser}:${dbUserPassword}@${id}:3306/${defaultDatabase}`
        if (isPublic) url = `mysql://${dbUser}:${dbUserPassword}@${domain}/${defaultDatabase}`
        await db.updateDatabase({ id, url })
        if (destinationDockerId && destinationDocker.isCoolifyProxyUsed) {
            await configureProxyForDatabase({ domain, id, port: 3306, isPublic })
        }
        return {
            status: 201
        }
    } catch (err) {
        return err
    }

}