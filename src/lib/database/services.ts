import { decrypt, encrypt } from "$lib/crypto"
import { dockerInstance } from "$lib/docker"
import cuid from "cuid"
import { generatePassword } from "."
import { prisma, PrismaErrorHandler } from "./common"

export async function listServices(teamId) {
    return await prisma.service.findMany({ where: { teams: { every: { id: teamId } } } })
}

export async function newService({ name, teamId }) {
    return await prisma.service.create({ data: { name, teams: { connect: { id: teamId } } } })
}

export async function getService({ id, teamId }) {
    const body = await prisma.service.findFirst({ where: { id, teams: { every: { id: teamId } } }, include: { destinationDocker: true, plausibleAnalytics: true, minio: true, vscodeserver: true, wordpress: true } })

    if (body.plausibleAnalytics?.postgresqlPassword) body.plausibleAnalytics.postgresqlPassword = decrypt(body.plausibleAnalytics.postgresqlPassword)
    if (body.plausibleAnalytics?.password) body.plausibleAnalytics.password = decrypt(body.plausibleAnalytics.password)
    if (body.plausibleAnalytics?.secretKeyBase) body.plausibleAnalytics.secretKeyBase = decrypt(body.plausibleAnalytics.secretKeyBase)

    if (body.minio?.rootUserPassword) body.minio.rootUserPassword = decrypt(body.minio.rootUserPassword)

    if (body.vscodeserver?.password) body.vscodeserver.password = decrypt(body.vscodeserver.password)

    if (body.wordpress?.mysqlPassword) body.wordpress.mysqlPassword = decrypt(body.wordpress.mysqlPassword)
    if (body.wordpress?.mysqlRootUserPassword) body.wordpress.mysqlRootUserPassword = decrypt(body.wordpress.mysqlRootUserPassword)

    return { ...body }
}

export async function configureServiceType({ id, type }) {
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
    } else if (type === 'vscodeserver') {
        const password = encrypt(generatePassword())
        await prisma.service.update({
            where: { id },
            data: { type, vscodeserver: { create: { password } } }
        })
    } else if (type === 'wordpress') {
        const mysqlUser = cuid()
        const mysqlPassword = encrypt(generatePassword())
        const mysqlRootUser = cuid()
        const mysqlRootUserPassword = encrypt(generatePassword())
        await prisma.service.update({
            where: { id },
            data: { type, wordpress: { create: { mysqlPassword, mysqlRootUserPassword, mysqlRootUser, mysqlUser } } }
        })

    }
}
export async function setService({ id, version }) {
    return await prisma.service.update({
        where: { id },
        data: { version }
    })

}
export async function updatePlausibleAnalyticsService({ id, fqdn, email, username, name }) {
    await prisma.plausibleAnalytics.update({ where: { serviceId: id }, data: { email, username } })
    await prisma.service.update({ where: { id }, data: { name, fqdn } })
}
export async function updateNocoDbOrMinioService({ id, fqdn, name }) {
    return await prisma.service.update({ where: { id }, data: { fqdn, name } })
}
export async function updateVsCodeServer({ id, fqdn, name }) {
    return await prisma.service.update({ where: { id }, data: { fqdn, name } })
}
export async function updateWordpress({ id, fqdn, name, mysqlDatabase, extraConfig }) {
    return await prisma.service.update({ where: { id }, data: { fqdn, name, wordpress: { update: { mysqlDatabase, extraConfig } } } })
}
export async function updateMinioService({ id, publicPort }) {
    return await prisma.minio.update({ where: { serviceId: id }, data: { publicPort } })
}


export async function removeService({ id }) {
    await prisma.plausibleAnalytics.deleteMany({ where: { serviceId: id } })
    await prisma.minio.deleteMany({ where: { serviceId: id } })
    await prisma.service.delete({ where: { id } })

}