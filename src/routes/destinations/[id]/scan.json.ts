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
        return container.Labels['coolify.configuration']
    })
    return {
        body: {
            containers: coolifyManaged
        }
    };
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const teamId = getTeam(request)
    const { id } = request.params
    const domain = request.body.get('domain')
    const found = await db.prisma.application.findFirst({ where: { domain } })
    if (found) {
        return {
            status: 200
        }
    }
    return {
        status: 404
    }

}

