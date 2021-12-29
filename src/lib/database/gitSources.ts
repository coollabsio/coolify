import { decrypt, encrypt } from "$lib/crypto"
import { prisma, PrismaErrorHandler } from "./common"

export async function listSources(teamId) {
    try {
        return await prisma.gitSource.findMany({ where: { teams: { every: { id: teamId } } }, include: { githubApp: true, gitlabApp: true } })
    } catch (err) {
        throw PrismaErrorHandler(err)
    }
}

export async function newSource({ name, teamId, type, htmlUrl, apiUrl, organization }) {
    try {
        const source = await prisma.gitSource.create({
            data: {
                teams: { connect: { id: teamId } },
                name,
                type,
                htmlUrl,
                apiUrl,
                organization
            }
        })
        return { status: 201, body: { id: source.id } }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}
export async function removeSource({ id }) {
    try {
        // TODO: Disconnect application with this sourceId! Maybe not needed?
        const source = await prisma.gitSource.delete({ where: { id }, include: { githubApp: true, gitlabApp: true } })
        if (source.githubAppId) await prisma.githubApp.delete({ where: { id: source.githubAppId } })
        if (source.gitlabAppId) await prisma.gitlabApp.delete({ where: { id: source.gitlabAppId } })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function getSource({ id, teamId }) {
    try {
        let body = await prisma.gitSource.findFirst({ where: { id, teams: { every: { id: teamId } } }, include: { githubApp: true, gitlabApp: true } })
        if (body?.githubApp?.clientSecret) body.githubApp.clientSecret = decrypt(body.githubApp.clientSecret)
        if (body?.githubApp?.webhookSecret) body.githubApp.webhookSecret = decrypt(body.githubApp.webhookSecret)
        if (body?.githubApp?.privateKey) body.githubApp.privateKey = decrypt(body.githubApp.privateKey)
        if (body?.gitlabApp?.appSecret) body.gitlabApp.appSecret = decrypt(body.gitlabApp.appSecret)
        return { ...body }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}
export async function addSource({ id, appId, teamId, name, oauthId, groupName, appSecret }) {
    try {
        const encrptedAppSecret = encrypt(appSecret)
        const source = await prisma.gitlabApp.create({ data: { teams: { connect: { id: teamId } }, appId, oauthId, groupName, appSecret: encrptedAppSecret, gitSource: { connect: { id } } } })
        return { status: 201, body: { id: source.id } }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function configureGitsource({ id, gitSourceId }) {
    try {
        await prisma.application.update({ where: { id }, data: { gitSource: { connect: { id: gitSourceId } } } })
        return { status: 201 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}