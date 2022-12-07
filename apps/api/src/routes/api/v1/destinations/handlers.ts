import type { FastifyRequest } from 'fastify';
import { FastifyReply } from 'fastify';
import sshConfig from 'ssh-config'
import fs from 'fs/promises'
import os from 'os';

import { createRemoteEngineConfiguration, decrypt, errorHandler, executeCommand, listSettings, prisma, startTraefikProxy, stopTraefikProxy } from '../../../../lib/common';
import { checkContainer } from '../../../../lib/docker';

import type { OnlyId } from '../../../../types';
import type { CheckDestination, ListDestinations, NewDestination, Proxy, SaveDestinationSettings } from './types';

export async function listDestinations(request: FastifyRequest<ListDestinations>) {
    try {
        const teamId = request.user.teamId;
        const { onlyVerified = false } = request.query
        let destinations = []
        if (teamId === '0') {
            destinations = await prisma.destinationDocker.findMany({ include: { teams: true } });
        } else {
            destinations = await prisma.destinationDocker.findMany({
                where: { teams: { some: { id: teamId } } },
                include: { teams: true }
            });
        }
        if (onlyVerified) {
            destinations = destinations.filter(destination => destination.engine || (destination.remoteEngine && destination.remoteVerified))
        }
        return {
            destinations
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function checkDestination(request: FastifyRequest<CheckDestination>) {
    try {
        const { network } = request.body;
        const found = await prisma.destinationDocker.findFirst({ where: { network } });
        if (found) {
            throw {
                message: `Network already exists: ${network}`
            };
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function getDestination(request: FastifyRequest<OnlyId>) {
    try {
        const { id } = request.params
        const teamId = request.user?.teamId;
        const destination = await prisma.destinationDocker.findFirst({
            where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
            include: { sshKey: true, application: true, service: true, database: true }
        });
        if (!destination && id !== 'new') {
            throw { status: 404, message: `Destination not found.` };
        }
        const settings = await listSettings();
        const payload = {
            destination,
            settings
        };
        return {
            ...payload
        };

    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function newDestination(request: FastifyRequest<NewDestination>, reply: FastifyReply) {
    try {
        const teamId = request.user.teamId;
        const { id } = request.params

        let { name, network, engine, isCoolifyProxyUsed, remoteIpAddress, remoteUser, remotePort } = request.body
        if (id === 'new') {
            if (engine) {
                const { stdout } = await await executeCommand({ command: `docker network ls --filter 'name=^${network}$' --format '{{json .}}'` });
                if (stdout === '') {
                    await await executeCommand({ command: `docker network create --attachable ${network}` });
                }
                await prisma.destinationDocker.create({
                    data: { name, teams: { connect: { id: teamId } }, engine, network, isCoolifyProxyUsed }
                });
                const destinations = await prisma.destinationDocker.findMany({ where: { engine } });
                const destination = destinations.find((destination) => destination.network === network);
                if (destinations.length > 0) {
                    const proxyConfigured = destinations.find(
                        (destination) => destination.network !== network && destination.isCoolifyProxyUsed === true
                    );
                    if (proxyConfigured) {
                        isCoolifyProxyUsed = !!proxyConfigured.isCoolifyProxyUsed;
                    }
                    await prisma.destinationDocker.updateMany({ where: { engine }, data: { isCoolifyProxyUsed } });
                }
                if (isCoolifyProxyUsed) {
                    await startTraefikProxy(destination.id);
                }
                return reply.code(201).send({ id: destination.id });
            } else {
                const destination = await prisma.destinationDocker.create({
                    data: { name, teams: { connect: { id: teamId } }, engine, network, isCoolifyProxyUsed, remoteEngine: true, remoteIpAddress, remoteUser, remotePort: Number(remotePort) }
                });
                return reply.code(201).send({ id: destination.id })
            }
        } else {
            await prisma.destinationDocker.update({ where: { id }, data: { name, engine, network } });
            return reply.code(201).send();
        }

    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function deleteDestination(request: FastifyRequest<OnlyId>) {
    try {
        const { id } = request.params
        const { network, remoteVerified, engine, isCoolifyProxyUsed } = await prisma.destinationDocker.findUnique({ where: { id } });
        if (isCoolifyProxyUsed) {
            if (engine || remoteVerified) {
                const { stdout: found } = await executeCommand({
                    dockerId: id,
                    command: `docker ps -a --filter network=${network} --filter name=coolify-proxy --format '{{.}}'`
                })
                if (found) {
                    await executeCommand({ dockerId: id, command: `docker network disconnect ${network} coolify-proxy` })
                    await executeCommand({ dockerId: id, command: `docker network rm ${network}` })
                }
            }
        }
        await prisma.destinationDocker.delete({ where: { id } });
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function saveDestinationSettings(request: FastifyRequest<SaveDestinationSettings>) {
    try {
        const { engine, isCoolifyProxyUsed } = request.body;
        await prisma.destinationDocker.updateMany({
            where: { engine },
            data: { isCoolifyProxyUsed }
        });

        return {
            status: 202
        }
        // return reply.code(201).send();
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function startProxy(request: FastifyRequest<Proxy>) {
    const { id } = request.params
    try {
        await startTraefikProxy(id);
        return {}
    } catch ({ status, message }) {
        await stopTraefikProxy(id);
        return errorHandler({ status, message })
    }
}
export async function stopProxy(request: FastifyRequest<Proxy>) {
    const { id } = request.params
    try {
        await stopTraefikProxy(id);
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function restartProxy(request: FastifyRequest<Proxy>) {
    const { id } = request.params
    try {
        await stopTraefikProxy(id);
        await startTraefikProxy(id);
        await prisma.destinationDocker.update({
            where: { id },
            data: { isCoolifyProxyUsed: true }
        });
        return {}
    } catch ({ status, message }) {
        await prisma.destinationDocker.update({
            where: { id },
            data: { isCoolifyProxyUsed: false }
        });
        return errorHandler({ status, message })
    }
}

export async function assignSSHKey(request: FastifyRequest) {
    try {
        const { id: sshKeyId } = request.body;
        const { id } = request.params;
        await prisma.destinationDocker.update({ where: { id }, data: { sshKey: { connect: { id: sshKeyId } } } })
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function verifyRemoteDockerEngineFn(id: string) {
    const { remoteIpAddress, network, isCoolifyProxyUsed } = await prisma.destinationDocker.findFirst({ where: { id } })
    const daemonJson = `daemon-${id}.json`
    try {
        await executeCommand({ sshCommand: true, command: `docker network inspect ${network}`, dockerId: id });
    } catch (error) {
        await executeCommand({ command: `docker network create --attachable ${network}`, dockerId: id });
    }

    try {
        await executeCommand({ sshCommand: true, command: `docker network inspect coolify-infra`, dockerId: id });
    } catch (error) {
        await executeCommand({ command: `docker network create --attachable coolify-infra`, dockerId: id });
    }

    if (isCoolifyProxyUsed) await startTraefikProxy(id);
    let isUpdated = false;
    let daemonJsonParsed = {
        "live-restore": true,
        "features": {
            "buildkit": true
        }
    };
    try {
        const { stdout: daemonJson } = await executeCommand({ sshCommand: true, dockerId: id, command: `cat /etc/docker/daemon.json` });
        daemonJsonParsed = JSON.parse(daemonJson);
        if (!daemonJsonParsed['live-restore'] || daemonJsonParsed['live-restore'] !== true) {
            isUpdated = true;
            daemonJsonParsed['live-restore'] = true

        }
        if (!daemonJsonParsed?.features?.buildkit) {
            isUpdated = true;
            daemonJsonParsed.features = {
                buildkit: true
            }
        }
    } catch (error) {
        isUpdated = true;
    }
    try {
        if (isUpdated) {
            await executeCommand({ shell: true, command: `echo '${JSON.stringify(daemonJsonParsed, null, 2)}' > /tmp/${daemonJson}` })
            await executeCommand({ dockerId: id, command: `scp /tmp/${daemonJson} ${remoteIpAddress}-remote:/etc/docker/daemon.json` });
            await executeCommand({ command: `rm /tmp/${daemonJson}` })
            await executeCommand({ sshCommand: true, dockerId: id, command: `systemctl restart docker` });
        }
        await prisma.destinationDocker.update({ where: { id }, data: { remoteVerified: true } })
    } catch (error) {
        throw new Error('Error while verifying remote docker engine')
    }
}
export async function verifyRemoteDockerEngine(request: FastifyRequest<OnlyId>, reply: FastifyReply) {
    const { id } = request.params;
    try {
        await verifyRemoteDockerEngineFn(id);
        return reply.code(201).send()
    } catch ({ status, message }) {
        await prisma.destinationDocker.update({ where: { id }, data: { remoteVerified: false } })
        return errorHandler({ status, message })
    }
}

export async function getDestinationStatus(request: FastifyRequest<OnlyId>) {
    try {
        const { id } = request.params
        const destination = await prisma.destinationDocker.findUnique({ where: { id } })
        const { found: isRunning } = await checkContainer({ dockerId: destination.id, container: 'coolify-proxy', remove: true })
        return {
            isRunning
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
