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
        const dbUserPassword = generatePassword()
        const rootUser = cuid()
        const rootUserPassword = generatePassword()
        const defaultDatabase = cuid()
        const version = '8.0.27'

        const database = await prisma.database.create({ data: { name, teams: { connect: { id: teamId } } } })

        const { id, domain } = database
        await updateDatabase({ id, name, domain, defaultDatabase, dbUser, dbUserPassword, rootUser, rootUserPassword, version })

        return { status: 201, body: { id: database.id } }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function getDatabase({ id, teamId }) {
    try {
        const body = await prisma.database.findFirst({ where: { id, teams: { every: { id: teamId } } }, include: { destinationDocker: true } })

        if (body.dbUserPassword) body.dbUserPassword = decrypt(body.dbUserPassword)
        if (body.rootUserPassword) body.rootUserPassword = decrypt(body.rootUserPassword)

        return { ...body }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function removeDatabase({ id }) {
    try {
        console.log(id)
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

export async function updateDatabase({ id, name, domain, defaultDatabase, dbUser, dbUserPassword, rootUser, rootUserPassword, version, url = null }) {
    try {
        const encryptedDbUserPassword = dbUserPassword && encrypt(dbUserPassword)
        const encryptedRootUserPassword = rootUserPassword && encrypt(rootUserPassword)
        await prisma.database.update({ where: { id }, data: { name, domain, defaultDatabase, dbUser, dbUserPassword: encryptedDbUserPassword, rootUser, rootUserPassword: encryptedRootUserPassword, version, url } })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}