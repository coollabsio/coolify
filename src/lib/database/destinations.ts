import { asyncExecShell, getEngine } from "$lib/common"
import { dockerInstance } from "$lib/docker"
import { configureCoolifyProxyOn, configureNetworkCoolifyProxy, startCoolifyProxy } from "$lib/haproxy"
import { getBaseImage } from "."
import { prisma, PrismaErrorHandler } from "./common"

export async function checkCoolifyProxy(engine) {
    const host = getEngine(engine)
    let haProxyFound = false
    try {
        const { stdout } = await asyncExecShell(`DOCKER_HOST="${host}" docker inspect --format '{{json .State}}' coolify-haproxy`)
        if (JSON.parse(stdout).Status === 'exited') {
            await asyncExecShell(`DOCKER_HOST="${host}" docker rm coolify-haproxy`)
        }
        if (JSON.parse(stdout).Running) {
            haProxyFound = true
        }

    } catch (err) {
        // HAProxy not found
    }
    return haProxyFound
}

async function installCoolifyProxy(engine) {
    const found = await checkCoolifyProxy(engine)
    if (!found) {
        try {
            await startCoolifyProxy(engine)
        } catch (err) {
            console.log(err)
        }
    }
    return
}

async function uninstallCoolifyProxy(engine) {
    const found = await checkCoolifyProxy(engine)
    if (found) {
        const host = getEngine(engine)
        try {
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

export async function configureDestinationForApplication({ id, destinationId }) {
    try {
        await prisma.application.update({ where: { id }, data: { destinationDocker: { connect: { id: destinationId } } } })
        return { status: 201 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}
export async function configureDestinationForDatabase({ id, destinationId }) {
    try {
        await prisma.database.update({ where: { id }, data: { destinationDocker: { connect: { id: destinationId } } } })
        
        const { destinationDockerId, destinationDocker, version, type } = await prisma.database.findUnique({ where: { id }, include: { destinationDocker: true } })

        if (destinationDockerId) {
            const docker = dockerInstance({ destinationDocker })
            try {
                if (type && version) {
                    const baseImage = getBaseImage(type)
                    docker.engine.pull(`${baseImage}:${version}`)
                    console.log(`pull initiated for ${baseImage}:${version}`)
                }
            } catch (error) {
                // console.log(error)
            }
        }
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
        const destinationDocker = {
            engine,
            network
        }
        const docker = dockerInstance({ destinationDocker })
        const networks = await docker.engine.listNetworks()
        let found = networks.find(network => network.Name === destinationDocker.network)
        if (!found) {
            await docker.engine.createNetwork({ name: network, attachable: true })
        }
        found = networks.find(network => network.Name === destinationDocker.network)
        await prisma.destinationDocker.create({ data: { name, subnet: found.IPAM.Config[0].Subnet, teams: { connect: { id: teamId } }, isSwarm, engine, network, isCoolifyProxyUsed } })
        const destinations = await prisma.destinationDocker.findMany({ where: { engine } })
        const destination = destinations.find(destination => destination.network === network)

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
            await installCoolifyProxy(engine)
            await configureNetworkCoolifyProxy(engine)
        } else {
            await uninstallCoolifyProxy(engine)
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
            const host = getEngine(destination.engine)
            await asyncExecShell(`DOCKER_HOST="${host}" docker network disconnect ${destination.network} coolify-haproxy`)
            await asyncExecShell(`DOCKER_HOST="${host}" docker network rm ${destination.network}`)
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
        if (isCoolifyProxyUsed) {
            await installCoolifyProxy(engine)
            await configureNetworkCoolifyProxy(engine)
        } else {
            // TODO: must check if other destination is using the proxy??? or not?
            const domain = await prisma.setting.findUnique({ where: { name: 'domain' }, rejectOnNotFound: false })
            if (!domain) {
                await uninstallCoolifyProxy(engine)
            } else {
                return {
                    stastus: 500,
                    body: {
                        message: 'You can not disable the Coolify proxy while the domain is set for Coolify itself.'
                    }
                }
            }
        }
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}