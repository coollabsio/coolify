import { asyncExecShell, getEngine } from "$lib/common"
import { dockerInstance } from "$lib/docker"
import { defaultProxyImageDatabase, startCoolifyProxy } from "$lib/haproxy"
import { getBaseImage } from "."
import { prisma, PrismaErrorHandler } from "./common"


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
                    docker.engine.pull(defaultProxyImageDatabase)
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
        const  found = networks.find(network => network.Name === destinationDocker.network)
        if (!found) await docker.engine.createNetwork({ name: network, attachable: true })
        await prisma.destinationDocker.create({ data: { name, teams: { connect: { id: teamId } }, isSwarm, engine, network, isCoolifyProxyUsed } })
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

        if (isCoolifyProxyUsed) await startCoolifyProxy(engine)
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

        // if (isCoolifyProxyUsed) {
        //     await installCoolifyProxy(engine)
        //     await configureNetworkCoolifyProxy(engine)
        // } else {
        //     // TODO: must check if other destination is using the proxy??? or not?
        //     const domain = await prisma.setting.findUnique({ where: { name: 'domain' }, rejectOnNotFound: false })
        //     if (!domain) {
        //         await uninstallCoolifyProxy(engine)
        //     } else {
        //         return {
        //             stastus: 500,
        //             body: {
        //                 message: 'You can not disable the Coolify proxy while the domain is set for Coolify itself.'
        //             }
        //         }
        //     }
        // }
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}