import { asyncExecShell, createDirectories, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { promises as fs } from 'fs';
import yaml from 'js-yaml';
import type { RequestHandler } from '@sveltejs/kit';
import { letsEncrypt } from '$lib/letsencrypt';
import { configureSimpleServiceProxyOn, startHttpProxy, startTcpProxy } from '$lib/haproxy';
import getPort from 'get-port';
import { getDomain } from '$lib/components/common';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params

    try {
        const service = await db.getService({ id, teamId })
        const { type, version, fqdn, destinationDockerId, destinationDocker, minio: { rootUser, rootUserPassword } } = service

        const domain = getDomain(fqdn)
        const isHttps = fqdn.startsWith('https://')

        const network = destinationDockerId && destinationDocker.network
        const host = getEngine(destinationDocker.engine)
        const publicPort = await getPort()
        const consolePort = 9001
        const apiPort = 9000
        const { workdir } = await createDirectories({ repository: type, buildId: id })

        const config = {
            image: `minio/minio:${version}`,
            volume: `${id}-minio-data:/data`,
            environmentVariables: {
                MINIO_ROOT_USER: rootUser,
                MINIO_ROOT_PASSWORD: rootUserPassword,
                MINIO_BROWSER_REDIRECT_URL: fqdn
            },
        }
        const composeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: `minio/minio:${version}`,
                    command: `server /data --console-address ":${consolePort}"`,
                    environment: config.environmentVariables,
                    networks: [network],
                    volumes: [config.volume],
                    restart: 'always',
                },
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                [config.volume.split(':')[0]]: {
                    external: true
                },

            },

        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile))
        try {
            await asyncExecShell(`DOCKER_HOST=${host} docker volume create ${config.volume.split(':')[0]}`)
        } catch (error) {
            console.log(error)
        }
        try {

            await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`)
            await configureSimpleServiceProxyOn({ id, domain, port: consolePort })

            await db.updateMinioService({ id, publicPort })
            await startHttpProxy(destinationDocker, id, publicPort, apiPort)

            if (isHttps) {
                await letsEncrypt({ domain, id })
            }
            return {
                status: 200
            }
        } catch (error) {
            console.log(error)
            return {
                status: 500,
                body: {
                    message: error
                }
            }
        }

    } catch (err) {
        return err
    }

}