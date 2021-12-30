import { asyncExecShell } from "$lib/common"
import { dockerInstance } from "$lib/docker"
import { prisma, PrismaErrorHandler } from "./common"

// TODO: add uninstall function, remove all coolify proxies

async function checkCoolifyProxy({ engine }) {
    let haProxyFound = false
    try {
        const host = getHost({ engine })
        await asyncExecShell(`DOCKER_HOST="${host}" docker inspect coolify-haproxy`)
        haProxyFound = true
    } catch (err) {
        // HAProxy not found
    }
    return haProxyFound
}
export function getHost({ engine }) {
    return engine === '/var/run/docker.sock' ? 'unix:///var/run/docker.sock' : `tcp://${engine}:2375`
}
async function installCoolifyProxy({ engine, destinations }) {
    const found = await checkCoolifyProxy({ engine })
    if (!found) {
        try {
            const host = getHost({ engine })
            await asyncExecShell(`DOCKER_HOST="${host}" docker run --restart always --add-host 'host.docker.internal:host-gateway' --network coolify-infra -p "80:80" -p "443:443" -p "8404:8404" -p "5555:5555" --name coolify-haproxy -d coollabsio/haproxy-alpine:1.0.0-rc.1`)

        } catch (err) {
            console.log(err)
        }
    }
    destinations.forEach(async (destination) => {
        try {
            await asyncExecShell(`docker network connect ${destination.network} coolify-haproxy`)
        } catch (err) {
            // TODO: handle error
        }
    })
    return
}

async function uninstallCoolifyProxy({ engine }) {
    const found = await checkCoolifyProxy({ engine })
    if (found) {
        try {
            const host = getHost({ engine })
            await asyncExecShell(`DOCKER_HOST="${host}" docker stop -t 0 coolify-haproxy && docker rm coolify-haproxy`)
        } catch (err) {
            console.log(err)
        }
    }
    return
}

export async function listDestinations(teamId) {
    return await prisma.destinationDocker.findMany({ where: { teams: { every: { id: teamId } } } })
}

export async function configureDestination({ id, destinationId }) {
    try {
        await prisma.application.update({ where: { id }, data: { destinationDocker: { connect: { id: destinationId } } } })
        return { status: 201 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}
export async function updateDestination({ id, name, isSwarm, engine, network }) {
    try {
        await prisma.destinationDocker.update({ where: { id }, data: { name, isSwarm, engine, network, } })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}


export async function newDestination({ name, teamId, isSwarm, engine, network, isCoolifyProxyUsed }) {
    try {
        const destination = await prisma.destinationDocker.create({ data: { name, teams: { connect: { id: teamId } }, isSwarm, engine, network, isCoolifyProxyUsed } })

        const destinationDocker = {
            engine,
            network
        }
        const docker = dockerInstance({ destinationDocker })
        const networks = await docker.engine.listNetworks()
        const found = networks.find(network => network.Name === destinationDocker.network)
        if (!found) {
            await docker.engine.createNetwork({ name: network, attachable: true })
        }

        const destinations = await prisma.destinationDocker.findMany({ where: { engine } })

        if (destinations.length > 0) {
            const proxyConfigured = destinations.find(destination => destination.network !== network && destination.isCoolifyProxyUsed === true)
            if (proxyConfigured) {
                if (proxyConfigured.isCoolifyProxyUsed) {
                    isCoolifyProxyUsed = true
                } else {
                    isCoolifyProxyUsed = false
                }
            }
            await prisma.destinationDocker.updateMany({ where: { engine }, data: { isCoolifyProxyUsed } })
        }

        if (isCoolifyProxyUsed) {
            await installCoolifyProxy({ engine, destinations })
        } else {
            await uninstallCoolifyProxy({ engine })
        }
        return {
            status: 201, body: { id: destination.id }
        }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}
export async function removeDestination({ id }) {
    try {
        const destination = await prisma.destinationDocker.delete({ where: { id } })
        if (destination.isCoolifyProxyUsed) {
            await asyncExecShell(`docker network disconnect ${destination.network} coolify-haproxy`)
        }
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function getDestination({ id, teamId }) {
    try {
        const body = await prisma.destinationDocker.findFirst({ where: { id, teams: { every: { id: teamId } } } })
        return { ...body }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}
export async function getDestinationByApplicationId({ id, teamId }) {
    try {
        const body = await prisma.destinationDocker.findFirst({ where: { application: { some: { id } }, teams: { every: { id: teamId } } } })
        return { ...body }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function setDestinationSettings({ engine, isCoolifyProxyUsed }) {
    try {
        await prisma.destinationDocker.updateMany({ where: { engine }, data: { isCoolifyProxyUsed } })
        const destinations = await prisma.destinationDocker.findMany({ where: { engine } })
        if (isCoolifyProxyUsed) {
            await installCoolifyProxy({ engine, destinations })
        } else {
            await uninstallCoolifyProxy({ engine })
        }
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}