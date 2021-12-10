import { asyncExecShell, getTeam, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { dockerInstance } from '$lib/docker';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const teamId = getTeam(request)
    const { id } = request.params

    const destinationDocker = await db.getDestination({ id, teamId })
    const docker = dockerInstance({ destinationDocker })
    const listContainers = await docker.engine.listContainers()
    const containers = listContainers.filter((container) => {
        return container.Labels['coolify.configuration']
    })
    return {
        body: {
            containers: containers.map(
                (container) =>
                    container.Labels['coolify.configuration'] &&
                    JSON.parse(Buffer.from(container.Labels['coolify.configuration'], 'base64').toString())
            )
        }
    };
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const teamId = getTeam(request)
    const { id } = request.params
    const domain = request.body.get('domain')
    const projectId = Number(request.body.get('projectId'))
    const repository = request.body.get('repository')
    const branch = request.body.get('branch')
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

