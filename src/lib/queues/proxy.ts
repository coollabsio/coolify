import { prisma } from "$lib/database"
import { dockerInstance } from "$lib/docker"
import { configureProxy } from "$lib/haproxy"

export default async function () {
    const destinationDockers = await prisma.destinationDocker.findMany({})
    for (const destination of destinationDockers) {
        if (destination.isCoolifyProxyUsed) {
            const docker = dockerInstance({ destinationDocker: destination })
            const containers = await docker.engine.listContainers({ all: true })
            const configurations = containers.filter(container => container.Labels['coolify.managed']).map(container => container.Labels['coolify.configuration']).map(configuration => JSON.parse(Buffer.from(configuration, 'base64').toString()))
            for (const configuration of configurations) {
                const { domain, applicationId, port } = configuration
                const application = await prisma.application.findUnique({ where: { id: applicationId }, include: { settings: true } })
                const { forceSSL } = application.settings
                await configureProxy({ domain, applicationId, port, forceSSL })
            }
        }
    }
}