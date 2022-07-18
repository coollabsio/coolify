import type { FastifyRequest } from 'fastify';
import { FastifyReply } from 'fastify';
import { asyncExecShell, errorHandler, listSettings, prisma, startCoolifyProxy, startTraefikProxy, stopTraefikProxy } from '../../../../lib/common';
import { checkContainer, dockerInstance, getEngine } from '../../../../lib/docker';

import type { OnlyId } from '../../../../types';
import type { CheckDestination, NewDestination, Proxy, SaveDestinationSettings } from './types';

export async function listDestinations(request: FastifyRequest) {
    try {
        const teamId = request.user.teamId;
        let destinations = []
        if (teamId === '0') {
            destinations = await prisma.destinationDocker.findMany({ include: { teams: true } });
        } else {
            destinations = await prisma.destinationDocker.findMany({
                where: { teams: { some: { id: teamId } } },
                include: { teams: true }
            });
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
            where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } }
        });
        if (!destination && id !== 'new') {
            throw { status: 404, message: `Destination not found.` };
        }
        const settings = await listSettings();
        let payload = {
            destination,
            settings,
            state: false
        };

        if (destination?.remoteEngine) {
            // const { stdout } = await asyncExecShell(
            // 	`ssh -p ${destination.port} ${destination.user}@${destination.ipAddress} "docker ps -a"`
            // );
            // console.log(stdout)
            // const engine = await generateRemoteEngine(destination);
            // // await saveSshKey(destination);
            // payload.state = await checkContainer(engine, 'coolify-haproxy');
        } else {
            const containerName = 'coolify-proxy';
            payload.state =
                destination?.engine && (await checkContainer(destination.engine, containerName));
        }
        return {
            ...payload
        };

    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function newDestination(request: FastifyRequest<NewDestination>, reply: FastifyReply) {
    try {
        const { id } = request.params
        let { name, network, engine, isCoolifyProxyUsed } = request.body
        const teamId = request.user.teamId;
        if (id === 'new') {
            const host = getEngine(engine);
            const docker = dockerInstance({ destinationDocker: { engine, network } });
            const found = await docker.engine.listNetworks({ filters: { name: [`^${network}$`] } });
            if (found.length === 0) {
                await asyncExecShell(`DOCKER_HOST=${host} docker network create --attachable ${network}`);
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
                const settings = await prisma.setting.findFirst();
                if (settings?.isTraefikUsed) {
                    await startTraefikProxy(engine);
                } else {
                    await startCoolifyProxy(engine);
                }
            }
            return reply.code(201).send({ id: destination.id });
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
        const destination = await prisma.destinationDocker.delete({ where: { id } });
        if (destination.isCoolifyProxyUsed) {
            const host = getEngine(destination.engine);
            const { network } = destination;
            const settings = await prisma.setting.findFirst();
            const containerName = settings.isTraefikUsed ? 'coolify-proxy' : 'coolify-haproxy';
            const { stdout: found } = await asyncExecShell(
                `DOCKER_HOST=${host} docker ps -a --filter network=${network} --filter name=${containerName} --format '{{.}}'`
            );
            if (found) {
                await asyncExecShell(
                    `DOCKER_HOST="${host}" docker network disconnect ${network} ${containerName}`
                );
                await asyncExecShell(`DOCKER_HOST="${host}" docker network rm ${network}`);
            }
        }
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
    const { engine } = request.body;
    try {
        await startTraefikProxy(engine);
        return {}
    } catch ({ status, message }) {
        await stopTraefikProxy(engine);
        return errorHandler({ status, message })
    }
}
export async function stopProxy(request: FastifyRequest<Proxy>) {
    const { engine } = request.body;
    try {
        await stopTraefikProxy(engine);
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function restartProxy(request: FastifyRequest<Proxy>) {
    const { engine } = request.body;
    try {
        await stopTraefikProxy(engine);
        await startTraefikProxy(engine);
        await prisma.destinationDocker.updateMany({
            where: { engine },
            data: { isCoolifyProxyUsed: true }
        });
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
