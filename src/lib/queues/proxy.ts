import { asyncExecShell } from "$lib/common"
import { checkCoolifyProxy, prisma } from "$lib/database"
import { dockerInstance } from "$lib/docker"
import { configureCoolifyProxyOn, configureProxyForApplication, startCoolifyProxy } from "$lib/haproxy"

export default async function () {
    const destinationDockers = await prisma.destinationDocker.findMany({})
    for (const destination of destinationDockers) {
        if (destination.isCoolifyProxyUsed) {
            const docker = dockerInstance({ destinationDocker: destination })
            const containers = await docker.engine.listContainers({ all: true })
            const configurations = containers.filter(container => container.Labels['coolify.managed'] && container.Labels['coolify.type'] === 'application').map(container => container.Labels['coolify.configuration']).map(configuration => JSON.parse(Buffer.from(configuration, 'base64').toString()))
            for (const configuration of configurations) {
                const { domain, applicationId, port } = configuration
                const application = await prisma.application.findUnique({ where: { id: applicationId }, include: { settings: true } })
                const { forceSSL } = application.settings
                await configureProxyForApplication({ domain, applicationId, port, forceSSL })
            }
        }
    }
    const domain = await prisma.setting.findUnique({ where: { name: 'domain' }, rejectOnNotFound: false })
    if (domain) {
        const found = await checkCoolifyProxy('/var/run/docker.sock')
        if (!found) await startCoolifyProxy('/var/run/docker.sock')
        await configureCoolifyProxyOn({ domain: domain.value })

    }
}