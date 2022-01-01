import { prisma } from "$lib/database"
import { dockerInstance } from "$lib/docker"
import { configureProxy } from "$lib/haproxy"

export default async function () {
    const destinationDockers = await prisma.destinationDocker.findMany({})
    destinationDockers.forEach(async destination => {
        if (destination.isCoolifyProxyUsed) {
            const docker = dockerInstance({ destinationDocker: destination })
            const containers = await docker.engine.listContainers({ all: true })
            const configurations = containers.filter(container => container.Labels['coolify.managed']).map(container => container.Labels['coolify.configuration']).map(configuration => JSON.parse(Buffer.from(configuration, 'base64').toString()))
            configurations.forEach(async configuration => {
                const { domain, applicationId, port } = configuration
                await configureProxy({ domain, applicationId, port })
            })
        }
    })
}