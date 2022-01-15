import { asyncExecShell } from "$lib/common"

export default async function () {
    try {
        return await asyncExecShell(`docker run --rm --name certbot-renewal -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs renew`)
    } catch (error) {
        console.log(error)
    }
}