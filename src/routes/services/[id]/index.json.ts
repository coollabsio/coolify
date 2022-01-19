import { asyncExecShell, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { generateDatabaseConfiguration, getServiceImage, getVersions } from '$lib/database';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const service = await db.getService({ id, teamId })
    const { destinationDockerId, destinationDocker, type, version } = service

    let isRunning = false
    if (destinationDockerId) {
        const host = getEngine(destinationDocker.engine)
        const docker = dockerInstance({ destinationDocker })
        const baseImage = getServiceImage(type)
        docker.engine.pull(`${baseImage}:${version}`)
        try {
            const { stdout } = await asyncExecShell(`DOCKER_HOST=${host} docker inspect --format '{{json .State}}' ${id}`)

            if (JSON.parse(stdout).Running) {
                isRunning = true
            }
        } catch (error) {
            //
        }
    }
    return {
        body: {
            isRunning,
            service
        }
    };

}


// export const post: RequestHandler<Locals, FormData> = async (request) => {
//     const { teamId, status, body } = await getUserDetails(request);
//     if (status === 401) return { status, body }
//     const { id } = request.params

//     const name = request.body.get('name')
//     const defaultDatabase = request.body.get('defaultDatabase')
//     const dbUser = request.body.get('dbUser')
//     const dbUserPassword = request.body.get('dbUserPassword')
//     const rootUser = request.body.get('rootUser')
//     const rootUserPassword = request.body.get('rootUserPassword')
//     const version = request.body.get('version')

//     try {
//         return await db.updateDatabase({ id, name, defaultDatabase, dbUser, dbUserPassword, rootUser, rootUserPassword, version })
//     } catch (err) {
//         return err
//     }

// }