import type { FastifyReply, FastifyRequest } from 'fastify';
import fs from 'fs/promises';
import yaml from 'js-yaml';
import path from 'path';
import { asyncSleep, ComposeFile, createDirectories, decrypt, defaultComposeConfiguration, errorHandler, executeCommand, getServiceFromDB, isARM, makeLabelForServices, persistentVolumes, prisma, stopTcpHttpProxy } from '../common';
import { parseAndFindServiceTemplates } from '../../routes/api/v1/services/handlers';

import { ServiceStartStop } from '../../routes/api/v1/services/types';
import { OnlyId } from '../../types';
import { verifyAndDecryptServiceSecrets } from './common';

export async function stopService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const { destinationDockerId } = await getServiceFromDB({ id, teamId });
        if (destinationDockerId) {
            const { stdout: containers } = await executeCommand({
                dockerId: destinationDockerId,
                command: `docker ps -a --filter 'label=com.docker.compose.project=${id}' --format {{.ID}}`
            })
            if (containers) {
                const containerArray = containers.split('\n');
                if (containerArray.length > 0) {
                    for (const container of containerArray) {
                        await executeCommand({ dockerId: destinationDockerId, command: `docker stop -t 0 ${container}` })
                        await executeCommand({ dockerId: destinationDockerId, command: `docker rm --force ${container}` })
                    }
                }
            }
            return {}
        }
        throw { status: 500, message: 'Could not stop containers.' }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function startService(request: FastifyRequest<ServiceStartStop>, fastify: any) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const arm = isARM(service.arch);
        const { type, destinationDockerId, destinationDocker, persistentStorage, exposePort } =
            service;

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const template: any = await parseAndFindServiceTemplates(service, workdir, true)
        const network = destinationDockerId && destinationDocker.network;
        const config = {};
        for (const s in template.services) {
            let newEnvironments = []
            if (arm) {
                if (template.services[s]?.environmentArm?.length > 0) {
                    for (const environment of template.services[s].environmentArm) {
                        let [env, ...value] = environment.split("=");
                        value = value.join("=")
                        if (!value.startsWith('$$secret') && value !== '') {
                            newEnvironments.push(`${env}=${value}`)
                        }
                    }
                }
            } else {
                if (template.services[s]?.environment?.length > 0) {
                    for (const environment of template.services[s].environment) {
                        let [env, ...value] = environment.split("=");
                        value = value.join("=")
                        if (!value.startsWith('$$secret') && value !== '') {
                            newEnvironments.push(`${env}=${value}`)
                        }
                    }
                }
            }
            const secrets = await verifyAndDecryptServiceSecrets(id)
            for (const secret of secrets) {
                const { name, value } = secret
                if (value) {
                    const foundEnv = !!template.services[s].environment?.find(env => env.startsWith(`${name}=`))
                    const foundNewEnv = !!newEnvironments?.find(env => env.startsWith(`${name}=`))
                    if (foundEnv && !foundNewEnv) {
                        newEnvironments.push(`${name}=${value}`)
                    }
                    if (!foundEnv && !foundNewEnv && s === id) {
                        newEnvironments.push(`${name}=${value}`)
                    }
                }
            }
            const customVolumes = await prisma.servicePersistentStorage.findMany({ where: { serviceId: id } })
            let volumes = new Set()
            if (arm) {
                template.services[s]?.volumesArm && template.services[s].volumesArm.length > 0 && template.services[s].volumesArm.forEach(v => volumes.add(v))
            } else {
                template.services[s]?.volumes && template.services[s].volumes.length > 0 && template.services[s].volumes.forEach(v => volumes.add(v))
            }

            // Workaround: old plausible analytics service wrong volume id name
            if (service.type === 'plausibleanalytics' && service.plausibleAnalytics?.id) {
                let temp = Array.from(volumes)
                temp.forEach(a => {
                    const t = a.replace(service.id, service.plausibleAnalytics.id)
                    volumes.delete(a)
                    volumes.add(t)
                })
            }

            if (customVolumes.length > 0) {
                for (const customVolume of customVolumes) {
                    const { volumeName, path, containerId } = customVolume
                    if (volumes && volumes.size > 0 && !volumes.has(`${volumeName}:${path}`) && containerId === service) {
                        volumes.add(`${volumeName}:${path}`)
                    }
                }
            }
            let ports = []
            if (template.services[s].proxy?.length > 0) {
                for (const proxy of template.services[s].proxy) {
                    if (proxy.hostPort) {
                        ports.push(`${proxy.hostPort}:${proxy.port}`)
                    }
                }
            } else {
                if (template.services[s].ports?.length === 1) {
                    for (const port of template.services[s].ports) {
                        if (exposePort) {
                            ports.push(`${exposePort}:${port}`)
                        }
                    }
                }
            }
            let image = template.services[s].image
            if (arm && template.services[s].imageArm) {
                image = template.services[s].imageArm
            }
            config[s] = {
                container_name: s,
                build: template.services[s].build || undefined,
                command: template.services[s].command,
                entrypoint: template.services[s]?.entrypoint,
                image,
                expose: template.services[s].ports,
                ports: ports.length > 0 ? ports : undefined,
                volumes: Array.from(volumes),
                environment: newEnvironments,
                depends_on: template.services[s]?.depends_on,
                ulimits: template.services[s]?.ulimits,
                cap_drop: template.services[s]?.cap_drop,
                cap_add: template.services[s]?.cap_add,
                labels: makeLabelForServices(type),
                ...defaultComposeConfiguration(network),
            }
            // Generate files for builds
            if (template.services[s]?.files?.length > 0) {
                if (!config[s].build) {
                    config[s].build = {
                        context: workdir,
                        dockerfile: `Dockerfile.${s}`
                    }
                }
                let Dockerfile = `
                    FROM ${template.services[s].image}`
                for (const file of template.services[s].files) {
                    const { location, content } = file;
                    const source = path.join(workdir, location);
                    await fs.mkdir(path.dirname(source), { recursive: true });
                    await fs.writeFile(source, content);
                    Dockerfile += `
                        COPY .${location} ${location}`
                }
                await fs.writeFile(`${workdir}/Dockerfile.${s}`, Dockerfile);
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
        await startServiceContainers(fastify, id, teamId, destinationDocker.id, composeFileDestination)

        // Workaround: Stop old minio proxies
        if (service.type === 'minio') {
            try {
                const { stdout: containers } = await executeCommand({
                    dockerId: destinationDocker.id,
                    command:
                        `docker container ls -a --filter 'name=${id}-' --format {{.ID}}`
                });
                if (containers) {
                    const containerArray = containers.split('\n');
                    if (containerArray.length > 0) {
                        for (const container of containerArray) {
                            await executeCommand({ dockerId: destinationDockerId, command: `docker stop -t 0 ${container}` })
                            await executeCommand({ dockerId: destinationDockerId, command: `docker rm --force ${container}` })
                        }
                    }
                }
            } catch (error) { }
            try {
                const { stdout: containers } = await executeCommand({
                    dockerId: destinationDocker.id,
                    command:
                        `docker container ls -a --filter 'name=${id}-' --format {{.ID}}`
                });
                if (containers) {
                    const containerArray = containers.split('\n');
                    if (containerArray.length > 0) {
                        for (const container of containerArray) {
                            await executeCommand({ dockerId: destinationDockerId, command: `docker stop -t 0 ${container}` })
                            await executeCommand({ dockerId: destinationDockerId, command: `docker rm --force ${container}` })
                        }
                    }
                }
            } catch (error) { }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function startServiceContainers(fastify, id, teamId, dockerId, composeFileDestination) {
    try {
        fastify.io.to(teamId).emit(`start-service`, { serviceId: id, state: 'Pulling images...' })
        await executeCommand({ dockerId, command: `docker compose -f ${composeFileDestination} pull` })
    } catch (error) { }
    fastify.io.to(teamId).emit(`start-service`, { serviceId: id, state: 'Building images...' })
    await executeCommand({ dockerId, command: `docker compose -f ${composeFileDestination} build --no-cache` })
    fastify.io.to(teamId).emit(`start-service`, { serviceId: id, state: 'Creating containers...' })
    await executeCommand({ dockerId, command: `docker compose -f ${composeFileDestination} create` })
    fastify.io.to(teamId).emit(`start-service`, { serviceId: id, state: 'Starting containers...' })
    await executeCommand({ dockerId, command: `docker compose -f ${composeFileDestination} start` })
    await asyncSleep(1000);
    await executeCommand({ dockerId, command: `docker compose -f ${composeFileDestination} up -d` })
    fastify.io.to(teamId).emit(`start-service`, { serviceId: id, state: 0 })
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
            await executeCommand({
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
