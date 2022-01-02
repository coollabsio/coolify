import { dev } from "$app/env"
import { asyncExecShell, getHost } from "../common"

export default async function (job) {
  const { destinationDocker, domain } = job.data
  // Set SSL with Let's encrypt
  if (destinationDocker && !dev) {
    const host = getHost({ engine: destinationDocker.engine })
    // Deploy to docker
    // TODO: Must be localhost
      // saveBuildLog({ line: 'Requesting SSL cert.', buildId })
      const { stderr } = await asyncExecShell(`DOCKER_HOST=${host} docker run --rm --name certbot -p 9080:9080 -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs certonly --standalone --preferred-challenges http --http-01-address 0.0.0.0 --http-01-port 9080 -d ${domain} --agree-tos --non-interactive --register-unsafely-without-email --test-cert`)
      if (stderr) console.log(stderr)
      // saveBuildLog({ line: 'SSL cert requested successfully!', buildId })
      // saveBuildLog({ line: 'Parsing SSL cert.', buildId })
      await asyncExecShell(`DOCKER_HOST=${host} docker run --rm --name bash -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" blang/busybox-bash -c "cat /etc/letsencrypt/live/${domain}/fullchain.pem /etc/letsencrypt/live/${domain}/privkey.pem > /app/ssl/${domain}.pem"`)
      // await asyncExecShell(`cat /app/ssl/live/${domain}/fullchain.pem /app/ssl/live/${domain}/privkey.pem > /app/ssl/${domain}/${domain}.pem`)
      // saveBuildLog({ line: 'SSL cert parsed.', buildId })
      // saveBuildLog({ line: 'Reloading Haproxy', buildId })
      await asyncExecShell(`DOCKER_HOST=${host} docker kill -s HUP coolify-haproxy`)
      // saveBuildLog({ line: 'Reloading Haproxy done', buildId })
    } 
    if (dev) {
      console.log({ domain })
      console.log('not running letsencrypt')
    }
    // TODO: Implement remote docker engine

}