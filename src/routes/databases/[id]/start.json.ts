import { asyncExecShell, createDirectories, getHost, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { generatePassword } from '$lib/database';
// import { databaseQueue } from '$lib/queues';
import { promises as fs } from 'fs';
import yaml from 'js-yaml';
import type { RequestHandler } from '@sveltejs/kit';
import cuid from 'cuid';
import { makeLabelForDatabase } from '$lib/buildPacks/common';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }
    const { id } = request.params

    try {
        let environmentVariables = {};
        let image = null;
        let volume = null;
        let ulimits = {};

        const database = await db.getDatabase({ id, teamId })
        const { name, domain, dbUser, dbUserPassword, rootUser, rootUserPassword, defaultDatabase, version, type, destinationDockerId, destinationDocker } = database

        if (type === 'mysql') {
            environmentVariables = {
                MYSQL_USER: dbUser,
                MYSQL_PASSWORD: dbUserPassword,
                MYSQL_ROOT_PASSWORD: rootUserPassword,
                MYSQL_ROOT_USER: rootUser,
                MYSQL_DATABASE: defaultDatabase
            }
            image = `bitnami/mysql:${version}`;
            volume = `${id}-${type}-data:/bitnami/mysql/data`;
        }
        const network = destinationDockerId && destinationDocker.network
        const host = getHost({ engine: destinationDocker.engine })

        const volumeName = volume.split(':')[0]
        const labels = await makeLabelForDatabase({ id, image, volume })

        const { workdir, repodir } = await createDirectories({ repository: type, buildId: id })

        const composeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image,
                    networks: [network],
                    environment: environmentVariables,
                    volumes: [volume],
                    ulimits,
                    labels
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                [volumeName]: {
                    external: true
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile))
        try {
            await asyncExecShell(`DOCKER_HOST=${host} docker volume create ${volumeName}`)
        } catch (error) {
            console.log(error)
        }
        try {
            await asyncExecShell(`DOCKER_HOST=${host} docker-compose -f ${composeFileDestination} up -d`)
            const url = `mysql://${dbUser}:${dbUserPassword}@${domain}:3306/${defaultDatabase}`
            await db.updateDatabase({ id, name, domain, defaultDatabase, dbUser, dbUserPassword, rootUser, rootUserPassword, version, url })
            return {
                status: 200
            }
        } catch (error) {
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