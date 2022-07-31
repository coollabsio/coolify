import type { FastifyRequest } from 'fastify';
import { FastifyReply } from 'fastify';
import sshConfig from 'ssh-config'
import fs from 'fs/promises'
import os from 'os';

import { asyncExecShell, decrypt, errorHandler, executeDockerCmd, listSettings, prisma, startTraefikProxy, stopTraefikProxy } from '../../../../lib/common';
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
            include: { sshKey: true }
        });
        if (!destination && id !== 'new') {
            throw { status: 404, message: `Destination not found.` };
        }
        const settings = await listSettings();
        let payload = {
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
            console.log(engine)
            if (engine) {
                const { stdout } = await asyncExecShell(`DOCKER_HOST=unix:///var/run/docker.sock docker network ls --filter 'name=^${network}$' --format '{{json .}}'`);
                if (stdout === '') {
                    await asyncExecShell(`DOCKER_HOST=unix:///var/run/docker.sock docker network create --attachable ${network}`);
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
                    data: { name, teams: { connect: { id: teamId } }, engine, network, isCoolifyProxyUsed, remoteEngine: true, remoteIpAddress, remoteUser, remotePort }
                });
                return reply.code(201).send({ id: destination.id })
            }
        } else {
            await prisma.destinationDocker.update({ where: { id }, data: { name, engine, network } });
            return reply.code(201).send();
        }

    } catch ({ status, message }) {
        console.log({ status, message })
        return errorHandler({ status, message })
    }
}
export async function deleteDestination(request: FastifyRequest<OnlyId>) {
    try {
        const { id } = request.params
        const { network, remoteVerified, engine, isCoolifyProxyUsed } = await prisma.destinationDocker.findUnique({ where: { id } });
        if (isCoolifyProxyUsed) {
            if (engine || remoteVerified) {
                const { stdout: found } = await executeDockerCmd({
                    dockerId: id,
                    command: `docker ps -a --filter network=${network} --filter name=coolify-proxy --format '{{.}}'`
                })
                if (found) {
                    await executeDockerCmd({ dockerId: id, command: `docker network disconnect ${network} coolify-proxy` })
                    await executeDockerCmd({ dockerId: id, command: `docker network rm ${network}` })
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
        console.log({ status, message })
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
export async function verifyRemoteDockerEngine(request: FastifyRequest, reply: FastifyReply) {
    try {
        const { id } = request.params;
        const homedir = os.homedir();

        const { sshKey: { privateKey }, remoteIpAddress, remotePort, remoteUser, network } = await prisma.destinationDocker.findFirst({ where: { id }, include: { sshKey: true } })

        await fs.writeFile(`/tmp/id_rsa_verification_${id}`, decrypt(privateKey) + '\n', { encoding: 'utf8', mode: 400 })

        const host = `ssh://${remoteUser}@${remoteIpAddress}`

        const config = sshConfig.parse('')
        const found = config.find({ Host: remoteIpAddress })
        if (!found) {
            config.append({
                Host: remoteIpAddress,
                Port: remotePort.toString(),
                User: remoteUser,
                IdentityFile: `/tmp/id_rsa_verification_${id}`,
                StrictHostKeyChecking: 'no'
            })
        }
        try {
            await fs.stat(`${homedir}/.ssh/`)
        } catch (error) {
            await fs.mkdir(`${homedir}/.ssh/`)
        }
        await fs.writeFile(`${homedir}/.ssh/config`, sshConfig.stringify(config))

        const { stdout } = await asyncExecShell(`DOCKER_HOST=${host} docker network ls --filter 'name=${network}' --no-trunc --format "{{json .}}"`);

        if (!stdout) {
            await asyncExecShell(`DOCKER_HOST=${host} docker network create --attachable ${network}`);
        }

        await prisma.destinationDocker.update({ where: { id }, data: { remoteVerified: true } })
        return reply.code(201).send()

    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function getDestinationStatus(request: FastifyRequest<OnlyId>) {
    try {
        const { id } = request.params
        const destination = await prisma.destinationDocker.findUnique({ where: { id } })
        const isRunning = await checkContainer({ dockerId: destination.id, container: 'coolify-proxy' })
        return {
            isRunning
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
