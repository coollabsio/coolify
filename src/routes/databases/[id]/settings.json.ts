import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { generateDatabaseConfiguration } from '$lib/database';
import { configureDatabaseVisibility } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { status, body, teamId } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const isPublic = request.body.get('isPublic') === 'true' ? true : false

    try {
        const database = await db.getDatabase({ id, teamId })
        const { domain, destinationDockerId, destinationDocker } = database
        const { url } = generateDatabaseConfiguration(database)

        if (isPublic && !domain) {
            return {
                status: 500,
                body: {
                    message: 'You must provide a domain to make a database public'
                }
            }
        }
        await db.setDatabaseSettings({ id, isPublic })
        await db.updateDatabase({ id, url })
        if (destinationDockerId && destinationDocker.isCoolifyProxyUsed) {
            await configureDatabaseVisibility({ id, isPublic })
        }
        return {
            status: 201
        }
    } catch (err) {
        return err
    }

}