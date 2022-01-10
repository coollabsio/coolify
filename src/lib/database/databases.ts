import { decrypt, encrypt } from "$lib/crypto"
import cuid from "cuid"
import { generatePassword } from "."
import { prisma, PrismaErrorHandler } from "./common"

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
        const databases = await prisma.database.findMany({ orderBy: { port: 'desc' } })

        let port = 60000
        if (databases.length > 0) {
            port = databases[0].port + 1
        }

        const database = await prisma.database.create({ data: { name, port, defaultDatabase, dbUser, dbUserPassword, rootUser, rootUserPassword, teams: { connect: { id: teamId } }, settings: { create: { isPublic: false } } } })

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

export async function updateDatabase({ id, name = undefined, domain = undefined, defaultDatabase = undefined, dbUser = undefined, dbUserPassword = undefined, rootUser = undefined, rootUserPassword = undefined, version = undefined, url = undefined }) {
    try {
        const encryptedDbUserPassword = dbUserPassword && encrypt(dbUserPassword)
        const encryptedRootUserPassword = rootUserPassword && encrypt(rootUserPassword)
        await prisma.database.update({ where: { id }, data: { name, domain, defaultDatabase, dbUser, dbUserPassword: encryptedDbUserPassword, rootUser, rootUserPassword: encryptedRootUserPassword, version, url } })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function setDatabaseSettings({ id, isPublic }) {
    try {
        await prisma.database.update({ where: { id }, data: { settings: { upsert: { update: { isPublic }, create: { isPublic } } } } })
        return { status: 201 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}