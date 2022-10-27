import type { FastifyReply, FastifyRequest } from 'fastify';
import fs from 'fs/promises';
import yaml from 'js-yaml';
import path from 'path';
import { asyncSleep, ComposeFile, createDirectories, decrypt, defaultComposeConfiguration, errorHandler, executeDockerCmd, getServiceFromDB, isARM, makeLabelForServices, persistentVolumes, prisma } from '../common';
import { parseAndFindServiceTemplates } from '../../routes/api/v1/services/handlers';

import { ServiceStartStop } from '../../routes/api/v1/services/types';
import { OnlyId } from '../../types';

export async function stopService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const { destinationDockerId } = await getServiceFromDB({ id, teamId });
        if (destinationDockerId) {
            await executeDockerCmd({
                dockerId: destinationDockerId,
                command: `docker ps -a --filter 'label=com.docker.compose.project=${id}' --format {{.ID}}|xargs -r -n 1 docker stop -t 0`
            })
            await executeDockerCmd({
                dockerId: destinationDockerId,
                command: `docker ps -a --filter 'label=com.docker.compose.project=${id}' --format {{.ID}}|xargs -r -n 1 docker rm --force`
            })
            return {}
        }
        throw { status: 500, message: 'Could not stop containers.' }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function startService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const arm = isARM(service.arch)
        const { type, destinationDockerId, destinationDocker, persistentStorage, exposePort } =
            service;

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const template: any = await parseAndFindServiceTemplates(service, workdir, true)
        const network = destinationDockerId && destinationDocker.network;
        const config = {};
        for (const service in template.services) {
            let newEnvironments = []
            if (arm) {
                if (template.services[service]?.environmentArm?.length > 0) {
                    for (const environment of template.services[service].environmentArm) {
                        const [env, value] = environment.split("=");
                        if (!value.startsWith('$$secret') && value !== '') {
                            newEnvironments.push(`${env}=${value}`)
                        }
                    }
                }
            } else {
                if (template.services[service]?.environment?.length > 0) {
                    for (const environment of template.services[service].environment) {
                        const [env, value] = environment.split("=");
                        if (!value.startsWith('$$secret') && value !== '') {
                            newEnvironments.push(`${env}=${value}`)
                        }
                    }
                }
            }

            const secrets = await prisma.serviceSecret.findMany({ where: { serviceId: id } })
            for (const secret of secrets) {
                const { name, value } = secret
                if (value) {
                    const foundEnv = !!template.services[service].environment?.find(env => env.startsWith(`${name}=`))
                    const foundNewEnv = !!newEnvironments?.find(env => env.startsWith(`${name}=`))
                    if (foundEnv && !foundNewEnv) {
                        newEnvironments.push(`${name}=${decrypt(value)}`)
                    }
                }
            }
            const customVolumes = await prisma.servicePersistentStorage.findMany({ where: { serviceId: id } })
            let volumes = arm ? template.services[service].volumesArm : template.services[service].volumes
            if (customVolumes.length > 0) {
                for (const customVolume of customVolumes) {
                    const { volumeName, path, containerId } = customVolume
                    if (volumes && volumes.length > 0 && !volumes.includes(`${volumeName}:${path}`) && containerId === service) {
                        volumes.push(`${volumeName}:${path}`)
                    }
                }
            }
            config[service] = {
                container_name: service,
                build: template.services[service].build || undefined,
                command: template.services[service].command,
                entrypoint: template.services[service]?.entrypoint,
                image: arm ? template.services[service].imageArm : template.services[service].image,
                expose: template.services[service].ports,
                ...(exposePort ? { ports: [`${exposePort}:${exposePort}`] } : {}),
                volumes,
                environment: newEnvironments,
                depends_on: template.services[service]?.depends_on,
                ulimits: template.services[service]?.ulimits,
                cap_drop: template.services[service]?.cap_drop,
                cap_add: template.services[service]?.cap_add,
                labels: makeLabelForServices(type),
                ...defaultComposeConfiguration(network),
            }

            // Generate files for builds
            if (template.services[service]?.files?.length > 0) {
                if (!template.services[service].build) {
                    template.services[service].build = {
                        context: workdir,
                        dockerfile: `Dockerfile.${service}`
                    }
                }
                let Dockerfile = `
                    FROM ${template.services[service].image}`
                for (const file of template.services[service].files) {
                    const { source, destination, content } = file;
                    await fs.writeFile(source, content);
                    Dockerfile += `
                        COPY ./${path.basename(source)} ${destination}`
                }
                await fs.writeFile(`${workdir}/Dockerfile.${service}`, Dockerfile);
            }
        }
        const { volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: config,
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        }
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await startServiceContainers(destinationDocker.id, composeFileDestination)
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function startServiceContainers(dockerId, composeFileDestination) {
    try {
        await executeDockerCmd({ dockerId, command: `docker compose -f ${composeFileDestination} pull` })
    } catch (error) { }
    await executeDockerCmd({ dockerId, command: `docker compose -f ${composeFileDestination} build --no-cache` })
    await executeDockerCmd({ dockerId, command: `docker compose -f ${composeFileDestination} create` })
    await executeDockerCmd({ dockerId, command: `docker compose -f ${composeFileDestination} start` })
    await asyncSleep(1000);
    await executeDockerCmd({ dockerId, command: `docker compose -f ${composeFileDestination} up -d` })
}
export async function migrateAppwriteDB(request: FastifyRequest<OnlyId>, reply: FastifyReply) {
    try {
        const { id } = request.params
        const teamId = request.user.teamId;
        const {
            destinationDockerId,
            destinationDocker,
        } = await getServiceFromDB({ id, teamId });
        if (destinationDockerId) {
            await executeDockerCmd({
                dockerId: destinationDocker.id,
                command: `docker exec ${id} migrate`
            })
            return await reply.code(201).send()
        }
        throw { status: 500, message: 'Could cleanup logs.' }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
