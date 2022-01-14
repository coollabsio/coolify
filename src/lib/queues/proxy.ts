import { prisma } from "$lib/database"
import { dockerInstance } from "$lib/docker"
import { checkContainer, configureCoolifyProxyOn, configureProxyForApplication, startCoolifyProxy } from "$lib/haproxy"

export default async function () {
    const destinationDockers = await prisma.destinationDocker.findMany({})
    for (const destination of destinationDockers) {
        if (destination.isCoolifyProxyUsed) {
            const docker = dockerInstance({ destinationDocker: destination })
            const containers = await docker.engine.listContainers()
            const configurations = containers.filter(container => container.Labels['coolify.managed'])
            for (const configuration of configurations) {
                const parsedConfiguration = JSON.parse(Buffer.from(configuration.Labels['coolify.configuration'], 'base64').toString())
                if (configuration.Labels['coolify.type'] === 'standalone-application') {
                    const { domain, applicationId, port } = parsedConfiguration
                    const application = await prisma.application.findUnique({ where: { id: applicationId }, include: { settings: true } })
                    const { forceSSL } = application.settings
                    await configureProxyForApplication({ domain, applicationId, port, forceSSL })
                } //else if (configuration.Labels['coolify.type'] === 'standalone-database') {
                //     const { id, port } = parsedConfiguration
                //     const { privatePort } = await generateDatabaseConfiguration(parsedConfiguration)
                //     const { settings: { isPublic = false } } = await prisma.database.findUnique({ where: { id }, include: { settings: true } })
                //     await configureProxyForDatabase({ id, port, isPublic, privatePort })
                // }
            }
        }
    }
    const domain = await prisma.setting.findUnique({ where: { name: 'domain' }, rejectOnNotFound: false })
    if (domain) {
        const found = await checkContainer('/var/run/docker.sock', 'coolify-haproxy')
        if (!found) await startCoolifyProxy('/var/run/docker.sock')
        await configureCoolifyProxyOn({ domain: domain.value })
    }

}