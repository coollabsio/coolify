import { getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';
import jsonwebtoken from 'jsonwebtoken'

export const get: RequestHandler = async (request) => {
    const { status, body, teamId } = await getUserDetails(request, false);
    if (status === 401) return { status, body }

    const { id } = request.params
    const destinationDocker = await db.getDestinationByApplicationId({ id, teamId })
    const docker = dockerInstance({ destinationDocker })
    const listContainers = await docker.engine.listContainers({ filters: { network: [destinationDocker.network] } })
    const containers = listContainers.filter((container) => {
        return container.Labels['coolify.configuration'] && container.Labels['coolify.type'] === 'application'
    })
    const jsonContainers = containers.map(container => JSON.parse(Buffer.from(container.Labels['coolify.configuration'], 'base64').toString())).filter(container => container.type !== 'manual' && container.applicationId === id)
    return {
        body: {
            containers: jsonContainers
        }
    };

}
