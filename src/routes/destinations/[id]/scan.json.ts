import { asyncExecShell, getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const teamId = getTeam(request)
    const { id } = request.params

    const destinationDocker = await db.getDestination({ id, teamId })
    const docker = dockerInstance({ destinationDocker })
    const containers = await docker.engine.listContainers()
    const coolifyManaged = containers.filter((container) => {
        console.log(container.Labels.configuration)
        return container.Labels.configuration
        // const configuration = container.Labels.configuration && JSON.parse(container.Labels.configuration)
        // if (configuration.coolifyManaged === 'true') {
        //     return configuration

        // }
    })
    return {
        body: {
            containers:coolifyManaged
        }
    };
}

