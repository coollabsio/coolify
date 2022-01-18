import { asyncExecShell, getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const teamId = getTeam(request)
    const { id } = request.params

    const destinationDocker = await db.getDestination({ id, teamId })
    const docker = dockerInstance({ destinationDocker })
    const listContainers = await docker.engine.listContainers({ filters: { network: [destinationDocker.network] } })
    const containers = listContainers.filter((container) => {
        return container.Labels['coolify.configuration']  
    })
    const jsonContainers = containers.map(container => JSON.parse(Buffer.from(container.Labels['coolify.configuration'], 'base64').toString())).filter(container => container.type === 'manual')
    return {
        body: {
            containers:jsonContainers
        }
    };
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const teamId = getTeam(request)
    const { id } = request.params
    const domain = request.body.get('domain') || undefined
    const projectId = Number(request.body.get('projectId')) || undefined
    const repository = request.body.get('repository') || undefined
    const branch = request.body.get('branch') || undefined
    try {
        const foundByDomain = await db.prisma.application.findFirst({ where: { domain }, rejectOnNotFound: false })
        const foundByRepository = await db.prisma.application.findFirst({ where: { repository, branch, projectId }, rejectOnNotFound: false })
        if (foundByDomain) {
            return {
                status: 200,
                body: { by: 'domain', name: foundByDomain.name }
            }
        }
        if (foundByRepository) {
            return {
                status: 200,
                body: { by: 'repository', name: foundByRepository.name }
            }
        }
        return {
            status: 404
        }
    } catch (error) {
        console.log(error)
        return {
            status: 404
        }
    }
}

