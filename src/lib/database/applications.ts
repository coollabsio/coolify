import { decrypt } from "$lib/crypto"
import { prisma, PrismaErrorHandler } from "./common"

export async function listApplications(teamId) {
    return await prisma.application.findMany({ where: { teams: { every: { id: teamId } } } })
}

export async function newApplication({ name, teamId }) {
    try {
        const app = await prisma.application.create({ data: { name, teams: { connect: { id: teamId } } } })
        return { status: 201, body: { id: app.id } }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function importApplication({ name, teamId, domain, port, buildCommand, startCommand, installCommand }) {
    try {
        const app = await prisma.application.create({ data: { name, domain, port, buildCommand, startCommand, installCommand, teams: { connect: { id: teamId } } } })
        return { status: 201, body: { id: app.id } }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}


export async function getApplicationWebhook({ projectId, branch }) {
    try {
        let body = await prisma.application.findFirst({ where: { projectId, branch }, include: { destinationDocker: true, gitSource: { include: { githubApp: true, gitlabApp: true } }, secrets: true } })

        if (body.gitSource?.githubApp?.clientSecret) {
            body.gitSource.githubApp.clientSecret = decrypt(body.gitSource.githubApp.clientSecret)
        }
        if (body.gitSource?.githubApp?.webhookSecret) {
            body.gitSource.githubApp.webhookSecret = decrypt(body.gitSource.githubApp.webhookSecret)
        }
        if (body.gitSource?.githubApp?.privateKey) {
            body.gitSource.githubApp.privateKey = decrypt(body.gitSource.githubApp.privateKey)
        }
        if (body?.gitSource?.gitlabApp?.appSecret) {
            body.gitSource.gitlabApp.appSecret = decrypt(body.gitSource.gitlabApp.appSecret)
        }
        if (body?.secrets.length > 0) {
            body.secrets = body.secrets.map(s => {
                s.value = decrypt(s.value)
                return s
            })
        }

        return { ...body }
    } catch (e) {
        throw { status: 404, body: { message: e.message } }
    }
}
export async function getApplication({ id, teamId }) {
    try {
        let body = await prisma.application.findFirst({ where: { id, teams: { every: { id: teamId } } }, include: { destinationDocker: true, gitSource: { include: { githubApp: true, gitlabApp: true } }, secrets: true } })

        if (body.gitSource?.githubApp?.clientSecret) {
            body.gitSource.githubApp.clientSecret = decrypt(body.gitSource.githubApp.clientSecret)
        }
        if (body.gitSource?.githubApp?.webhookSecret) {
            body.gitSource.githubApp.webhookSecret = decrypt(body.gitSource.githubApp.webhookSecret)
        }
        if (body.gitSource?.githubApp?.privateKey) {
            body.gitSource.githubApp.privateKey = decrypt(body.gitSource.githubApp.privateKey)
        }
        if (body?.gitSource?.gitlabApp?.appSecret) {
            body.gitSource.gitlabApp.appSecret = decrypt(body.gitSource.gitlabApp.appSecret)
        }
        if (body?.secrets.length > 0) {
            body.secrets = body.secrets.map(s => {
                s.value = decrypt(s.value)
                return s
            })
        }

        return { ...body }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}


export async function configureGitRepository({ id, repository, branch, projectId }) {
    try {
        await prisma.application.update({ where: { id }, data: { repository, branch, projectId } })
        return { status: 201 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function configureBuildPack({ id, buildPack }) {
    try {
        await prisma.application.update({ where: { id }, data: { buildPack } })
        return { status: 201 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function configureApplication({ id, teamId, domain, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory }) {
    try {
        let application = await prisma.application.findFirst({ where: { id, teams: { every: { id: teamId } } } })
        if (application.domain !== domain && !application.oldDomain) {
            application = await prisma.application.update({ where: { id }, data: { domain, oldDomain: application.domain, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory } })
        } else {
            application = await prisma.application.update({ where: { id }, data: { domain, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory } })
        }
        return { status: 201, body: { application } }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function setApplicationSettings({ id, debugLogs }) {
    try {
        await prisma.application.update({ where: { id }, data: { debugLogs } })
        return { status: 201 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}