import { asyncExecShell } from "$lib/common"
import { checkCoolifyProxy, prisma } from "$lib/database"
import { dockerInstance } from "$lib/docker"
import { configureCoolifyProxyOn, configureProxyForApplication } from "$lib/haproxy"

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
                await configureProxyForApplication({ domain, applicationId, port, forceSSL })
            }
        }
    }
    const domain = await prisma.setting.findUnique({ where: { name: 'domain' }, rejectOnNotFound: false })
    if (domain) {
        const found = await checkCoolifyProxy({ engine: '/var/run/docker.sock' })
        if (!found) {
            await asyncExecShell(`docker run --restart always --add-host 'host.docker.internal:host-gateway' -v coolify-ssl-certs:/usr/local/etc/haproxy/ssl --network coolify-infra -p "80:80" -p "443:443" -p "8404:8404" -p "5555:5555" -p "3306:3306" --name coolify-haproxy -d coollabsio/haproxy-alpine:1.0.0-rc.1`)
        }
        await configureCoolifyProxyOn({ domain: domain.value })

    }
}