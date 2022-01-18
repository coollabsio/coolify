import { asyncExecShell, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { generateDatabaseConfiguration, getVersions } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const database = await db.getDatabase({ id, teamId })
    const { destinationDockerId, destinationDocker } = database

    let state = 'not started'
    if (destinationDockerId) {
        const host = getEngine(destinationDocker.engine)

        try {
            const { stdout } = await asyncExecShell(`DOCKER_HOST=${host} docker inspect --format '{{json .State}}' ${id}`)

            if (JSON.parse(stdout).Running) {
                state = 'running'
            }
        } catch (error) {
            // if (!error.stderr.includes('No such object')) {
            //     console.log(error)
            // }
        }
    }
    const configuration = generateDatabaseConfiguration(database)
    return {
        body: {
            privatePort: configuration?.privatePort,
            database,
            state,
            versions: getVersions(database.type)
        }
    };

}


export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }
    const { id } = request.params

    const name = request.body.get('name') || undefined
    const defaultDatabase = request.body.get('defaultDatabase') || undefined
    const dbUser = request.body.get('dbUser') || undefined
    const dbUserPassword = request.body.get('dbUserPassword') || undefined
    const rootUser = request.body.get('rootUser') || undefined
    const rootUserPassword = request.body.get('rootUserPassword') || undefined
    const version = request.body.get('version') || undefined

    try {
        return await db.updateDatabase({ id, name, defaultDatabase, dbUser, dbUserPassword, rootUser, rootUserPassword, version })
    } catch (err) {
        return err
    }

}