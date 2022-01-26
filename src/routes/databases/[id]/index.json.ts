import { asyncExecShell, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { generateDatabaseConfiguration, getVersions, PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
    const { teamId, status, body } = await getUserDetails(event);
    if (status === 401) return { status, body }

    const { id } = event.params
    try {
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
    } catch (error) {
        return PrismaErrorHandler(error)
    }
}


export const post: RequestHandler<Locals> = async (event) => {
    const { teamId, status, body } = await getUserDetails(event);
    if (status === 401) return { status, body }
    const { id } = event.params
    const { name, defaultDatabase, dbUser, dbUserPassword, rootUser, rootUserPassword, version } = await event.request.json()

    try {
        await db.updateDatabase({ id, name, defaultDatabase, dbUser, dbUserPassword, rootUser, rootUserPassword, version })
        return { status: 201 }
    } catch (error) {
        return PrismaErrorHandler(error)
    }

}