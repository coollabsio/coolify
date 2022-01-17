import { decrypt, encrypt } from "$lib/crypto"
import { dockerInstance } from "$lib/docker"
import cuid from "cuid"
import { generatePassword } from "."
import { prisma, PrismaErrorHandler } from "./common"

export async function listServices(teamId) {
    return await prisma.service.findMany({ where: { teams: { every: { id: teamId } } } })
}

export async function newService({ name, teamId }) {
    try {
        const service = await prisma.service.create({ data: { name, teams: { connect: { id: teamId } } } })
        return { status: 201, body: { id: service.id } }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function getService({ id, teamId }) {
    try {
        const body = await prisma.service.findFirst({ where: { id, teams: { every: { id: teamId } } }, include: { destinationDocker: true, plausibleAnalytics: true, minio: true } })

        if (body.plausibleAnalytics?.postgresqlPassword) body.plausibleAnalytics.postgresqlPassword = decrypt(body.plausibleAnalytics.postgresqlPassword)
        if (body.plausibleAnalytics?.password) body.plausibleAnalytics.password = decrypt(body.plausibleAnalytics.password)
        if (body.plausibleAnalytics?.secretKeyBase) body.plausibleAnalytics.secretKeyBase = decrypt(body.plausibleAnalytics.secretKeyBase)

        if (body.minio?.rootUserPassword) body.minio.rootUserPassword = decrypt(body.minio.rootUserPassword)

        return { ...body }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function configureServiceType({ id, type }) {
    try {
        if (type === 'plausibleanalytics') {
            const password = encrypt(generatePassword())
            const postgresqlUser = cuid()
            const postgresqlPassword = encrypt(generatePassword())
            const postgresqlDatabase = 'plausibleanalytics'
            const secretKeyBase = encrypt(generatePassword(64))

            await prisma.service.update({
                where: { id },
                data: { type, plausibleAnalytics: { create: { postgresqlDatabase, postgresqlUser, postgresqlPassword, password, secretKeyBase } } }
            })
        } else if (type === 'nocodb') {
            await prisma.service.update({
                where: { id },
                data: { type }
            })
        } else if (type === 'minio') {
            const rootUser = cuid()
            const rootUserPassword = encrypt(generatePassword())
            await prisma.service.update({
                where: { id },
                data: { type, minio: { create: { rootUser, rootUserPassword } } }
            })
        }

        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}
export async function setService({ id, version = undefined, type = undefined }) {
    try {
        await prisma.service.update({
            where: { id },
            data: { version, type }
        })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}
export async function updatePlausibleAnalyticsService({ id, domain = undefined, email = undefined, username = undefined, name = undefined }) {
    try {
        await prisma.plausibleAnalytics.update({ where: { serviceId: id }, data: { email, username } })
        await prisma.service.update({ where: { id }, data: { name, domain } })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}
export async function updateNocoDbService({ id, domain = undefined }) {
    try {
        await prisma.service.update({ where: { id }, data: { domain } })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function updateMinioService({ id, publicPort = undefined }) {
    try {
        await prisma.minio.update({ where: { serviceId: id }, data: { publicPort } })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}


export async function removeService({ id }) {
    try {
        await prisma.plausibleAnalytics.deleteMany({ where: { serviceId: id } })
        await prisma.minio.deleteMany({ where: { serviceId: id } })
        await prisma.service.delete({ where: { id } })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}