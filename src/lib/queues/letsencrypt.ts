import { dev } from "$app/env"
import { forceSSLOff, forceSSLOn, getNextTransactionId, reloadConfiguration } from "$lib/haproxy"
import { asyncExecShell, getHost } from "../common"

export default async function (job) {
  try {
    const { destinationDocker, domain, forceSSLChanged, isCoolify } = job.data
    if (dev) {
      if (forceSSLChanged) {
        await forceSSLOn({ domain })
      } else {
        await forceSSLOff({ domain })
      }
      return
    }
    if (isCoolify) {
      const { stderr } = await asyncExecShell(`docker run --rm --name certbot -p 9080:9080 -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs certonly --standalone --preferred-challenges http --http-01-address 0.0.0.0 --http-01-port 9080 -d ${domain} --agree-tos --non-interactive --register-unsafely-without-email --test-cert`)
      if (stderr) throw new Error(stderr)
      const { stderr: err } = await asyncExecShell(`docker run --rm --name bash -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" blang/busybox-bash cat /etc/letsencrypt/live/${domain}/fullchain.pem /etc/letsencrypt/live/${domain}/privkey.pem > /app/ssl/${domain}.pem`)
      if (err) throw new Error(err)
      return
    }
    // Set SSL with Let's encrypt
    if (destinationDocker) {
      if (forceSSLChanged) {
        const host = getHost({ engine: destinationDocker.engine })
        // saveBuildLog({ line: 'Requesting SSL cert.', buildId })
        const { stderr } = await asyncExecShell(`DOCKER_HOST=${host} docker run --rm --name certbot -p 9080:9080 -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs certonly --standalone --preferred-challenges http --http-01-address 0.0.0.0 --http-01-port 9080 -d ${domain} --agree-tos --non-interactive --register-unsafely-without-email --test-cert`)
        if (stderr) throw new Error(stderr)
        // saveBuildLog({ line: 'SSL cert requested successfully!', buildId })
        // saveBuildLog({ line: 'Parsing SSL cert.', buildId })
        const { stderr: err } = await asyncExecShell(`DOCKER_HOST=${host} docker run --rm --name bash -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" blang/busybox-bash cat /etc/letsencrypt/live/${domain}/fullchain.pem /etc/letsencrypt/live/${domain}/privkey.pem > /app/ssl/${domain}.pem`)
        if (err) throw new Error(err)

        // saveBuildLog({ line: 'SSL cert parsed.', buildId })
        // saveBuildLog({ line: 'Reloading Haproxy', buildId })
        await forceSSLOn({ domain })

      } else {
        await forceSSLOff({ domain })
      }
      await reloadConfiguration()

      // await asyncExecShell(`DOCKER_HOST=${host} docker kill -s HUP coolify-haproxy`)

    }

  } catch (err) {
    throw err
  }


}