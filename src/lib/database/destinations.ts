import { asyncExecShell } from "$lib/common"
import { prisma, PrismaErrorHandler } from "./common"

// TODO: add uninstall function, remove all coolify proxies

async function checkCoolifyProxy() {
    console.log('proxy - checking')
    let haProxyFound = false
    try {
        await asyncExecShell('DOCKER_HOST="unix:///var/run/docker.sock" docker inspect coolify-haproxy')
        haProxyFound = true
    } catch (err) {
        // HAProxy not found
    }
    return haProxyFound
}
async function installCoolifyProxy({ network }) {
    const found = await checkCoolifyProxy()
    if (!found) {
        console.log('proxy - installing')
        await asyncExecShell(`DOCKER_HOST="unix:///var/run/docker.sock" docker run --add-host 'host.docker.internal:host-gateway' --network coolify-infra -v "$(pwd)/data/haproxy/:/usr/local/etc/haproxy/" -p "80:80" -p "443:443" -p "8404:8404" -p "5555:5555" --name coolify-haproxy --rm -d haproxytech/haproxy-ubuntu:2.4`)
        await asyncExecShell(`docker network connect ${network} coolify-haproxy`)
    }
    console.log('proxy - nothing todo')
    return
}

async function uninstallCoolifyProxy() {
    const found = await checkCoolifyProxy()
    if (found) {
        console.log('proxy - uninstalling')
        const { stdout, stderr } = await asyncExecShell('DOCKER_HOST="unix:///var/run/docker.sock" docker stop -t 0 coolify-haproxy')
        console.log({ stdout, stderr })
    }
    console.log('proxy - nothing todo')
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
export async function updateDestination({ id, name, isSwarm, engine, network, isCoolifyProxyUsed }) {
    try {
        await prisma.destinationDocker.update({ where: { id }, data: { name, isSwarm, engine, network, isCoolifyProxyUsed } })
        if (isCoolifyProxyUsed) {
            await installCoolifyProxy({ network })
        } else {
            await uninstallCoolifyProxy()
        }
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}


export async function newDestination({ name, teamId, isSwarm, engine, network, isCoolifyProxyUsed }) {
    try {
        const destination = await prisma.destinationDocker.create({ data: { name, teams: { connect: { id: teamId } }, isSwarm, engine, network, isCoolifyProxyUsed } })
        if (isCoolifyProxyUsed) {
            await installCoolifyProxy({ network })
        } else {
            await uninstallCoolifyProxy()
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
        await prisma.destinationDocker.delete({ where: { id } })
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