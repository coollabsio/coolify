import { dev } from "$app/env"
import { forceSSLOffApplication, forceSSLOnApplication, getNextTransactionId, reloadConfiguration } from "$lib/haproxy"
import { asyncExecShell, getEngine } from "./common"
import * as db from '$lib/database';

export async function letsEncrypt({ domain, isCoolify = false, id }) {
    const { destinationDocker, destinationDockerId } = await db.prisma.application.findUnique({ where: { id }, include: { destinationDocker: true } })
    try {
        if (dev) {
            return await forceSSLOnApplication({ domain })
        } else {
            if (isCoolify) {
                await asyncExecShell(`docker run --rm --name certbot -p 9080:9080 -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs certonly --standalone --preferred-challenges http --http-01-address 0.0.0.0 --http-01-port 9080 -d ${domain} --agree-tos --non-interactive --register-unsafely-without-email`)

                const { stderr } = await asyncExecShell(`docker run --rm -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" alpine:latest cat /etc/letsencrypt/live/${domain}/fullchain.pem /etc/letsencrypt/live/${domain}/privkey.pem > /app/ssl/${domain}.pem`)
                if (stderr) throw new Error(stderr)
                await reloadConfiguration()
                return
            }
            // Set SSL with Let's encrypt
            if (destinationDockerId && destinationDocker) {
                const host = getEngine(destinationDocker.engine)
                // saveBuildLog({ line: 'Requesting SSL cert.', buildId })
                await asyncExecShell(`DOCKER_HOST=${host} docker run --rm --name certbot -p 9080:9080 -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs certonly --standalone --preferred-challenges http --http-01-address 0.0.0.0 --http-01-port 9080 -d ${domain} --agree-tos --non-interactive --register-unsafely-without-email`)
                // saveBuildLog({ line: 'SSL cert requested successfully!', buildId })
                // saveBuildLog({ line: 'Parsing SSL cert.', buildId })
                const { stderr } = await asyncExecShell(`DOCKER_HOST=${host} docker run --rm --name bash -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" alpine:latest cat /etc/letsencrypt/live/${domain}/fullchain.pem /etc/letsencrypt/live/${domain}/privkey.pem > /app/ssl/${domain}.pem`)
                if (stderr) throw new Error(stderr)

                // saveBuildLog({ line: 'SSL cert parsed.', buildId })
                // saveBuildLog({ line: 'Reloading Haproxy', buildId })
                await forceSSLOnApplication({ domain })

                await reloadConfiguration()
            }

        }
    } catch (err) {
        // if (!dev && !isCoolify) {
        //     await db.prisma.applicationSettings.updateMany({ where: { applicationId: id }, data: { forceSSL: false } })
        // }
        throw err
    }
}