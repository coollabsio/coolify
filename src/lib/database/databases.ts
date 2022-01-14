import { decrypt, encrypt } from "$lib/crypto"
import { dockerInstance } from "$lib/docker"
import cuid from "cuid"
import { generatePassword } from "."
import { prisma, PrismaErrorHandler } from "./common"
import getPort from 'get-port';

export async function listDatabases(teamId) {
    return await prisma.database.findMany({ where: { teams: { every: { id: teamId } } } })
}
export async function newDatabase({ name, teamId }) {
    try {
        const dbUser = cuid()
        const dbUserPassword = encrypt(generatePassword())
        const rootUser = cuid()
        const rootUserPassword = encrypt(generatePassword())
        const defaultDatabase = cuid()

        let publicPort = await getPort()
        let i = 0;

        do {
            const usedPorts = await prisma.database.findMany({ where: { publicPort } })
            if (usedPorts.length === 0) break
            publicPort = await getPort()
            i++;
        }
        while (i < 10);
        if (i === 9) {
            return {
                status: 500,
                body: {
                    message: 'No free port found!? Is it possible?'
                }
            }
        }
        const database = await prisma.database.create({ data: { name, publicPort, defaultDatabase, dbUser, dbUserPassword, rootUser, rootUserPassword, teams: { connect: { id: teamId } }, settings: { create: { isPublic: false } } } })

        return { status: 201, body: { id: database.id } }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function getDatabase({ id, teamId }) {
    try {
        const body = await prisma.database.findFirst({ where: { id, teams: { every: { id: teamId } } }, include: { destinationDocker: true, settings: true } })

        if (body.dbUserPassword) body.dbUserPassword = decrypt(body.dbUserPassword)
        if (body.rootUserPassword) body.rootUserPassword = decrypt(body.rootUserPassword)

        return { ...body }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function removeDatabase({ id }) {
    try {
        await prisma.databaseSettings.deleteMany({ where: { databaseId: id } })
        await prisma.database.delete({ where: { id } })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function configureDatabaseType({ id, type }) {
    try {
        await prisma.database.update({
            where: { id },
            data: { type }
        })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}
export async function setDatabase({ id, version = undefined, isPublic = undefined }) {
    try {
        await prisma.database.update({
            where: { id },
            data: { version, settings: { upsert: { update: { isPublic }, create: { isPublic } } } }
        })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}
export async function updateDatabase({ id, name = undefined, defaultDatabase = undefined, dbUser = undefined, dbUserPassword = undefined, rootUser = undefined, rootUserPassword = undefined, version = undefined }) {
    try {
        const encryptedDbUserPassword = dbUserPassword && encrypt(dbUserPassword)
        const encryptedRootUserPassword = rootUserPassword && encrypt(rootUserPassword)

        await prisma.database.update({ where: { id }, data: { name, defaultDatabase, dbUser, dbUserPassword: encryptedDbUserPassword, rootUser, rootUserPassword: encryptedRootUserPassword, version } })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function setDatabaseSettings({ id, isPublic }) {
    try {
        await prisma.databaseSettings.update({ where: { databaseId: id }, data: { isPublic } })
        return { status: 201 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function stopDatabase(database) {
    let everStarted = false
    const { id, destinationDockerId, destinationDocker } = database
    if (destinationDockerId) {
        const docker = dockerInstance({ destinationDocker })
        try {
            const container = docker.engine.getContainer(id)
            if (container) {
                everStarted = true
                await container.stop()
                await container.remove()
            }

        } catch (error) {
            console.log(error)
        }
    }
    return everStarted
}