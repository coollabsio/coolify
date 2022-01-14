import { decrypt, encrypt } from "$lib/crypto"
import { removeProxyConfiguration } from "$lib/haproxy"

import { removeAllPreviewsDestinationDocker, removeDestinationDocker } from "$lib/common"
import { prisma, PrismaErrorHandler } from "./common"
import { letsEncryptQueue } from "$lib/queues"
import { letsEncrypt } from "$lib/queues/letsencrypt"

export async function listApplications(teamId) {
    return await prisma.application.findMany({ where: { teams: { every: { id: teamId } } } })
}

export async function newApplication({ name, teamId }) {
    try {
        const app = await prisma.application.create({ data: { name, teams: { connect: { id: teamId } }, settings: { create: { debug: false, previews: false } } } })
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

export async function removeApplication({ id, teamId }) {
    try {
        const { domain, destinationDockerId, destinationDocker } = await prisma.application.findUnique({ where: { id }, include: { destinationDocker: true } })

        await prisma.applicationSettings.deleteMany({ where: { application: { id } } })
        await prisma.buildLog.deleteMany({ where: { applicationId: id } })
        await prisma.secret.deleteMany({ where: { applicationId: id } })
        await prisma.application.deleteMany({ where: { id, teams: { some: { id: teamId } } } })
        let previews = []
        if (destinationDockerId) {
            await removeDestinationDocker({ id, destinationDocker })
            previews = await removeAllPreviewsDestinationDocker({ id, destinationDocker })
        }
        if (domain) {
            try {
                await removeProxyConfiguration({ domain })
                if (previews.length > 0) {
                    previews.forEach(async preview => {
                        await removeProxyConfiguration({ domain: `${preview}.${domain}` })
                    })
                }
            } catch (error) {
                console.log(error)
            }
        }


        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function getApplicationWebhook({ projectId, branch }) {
    try {
        let body = await prisma.application.findFirst({ where: { projectId, branch }, include: { destinationDocker: true, settings: true, gitSource: { include: { githubApp: true, gitlabApp: true } }, secrets: true } })

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
        if (body?.gitSource?.gitlabApp?.webhookToken) {
            body.gitSource.gitlabApp.webhookToken = decrypt(body.gitSource.gitlabApp.webhookToken)
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
        let body = await prisma.application.findFirst({ where: { id, teams: { every: { id: teamId } } }, include: { destinationDocker: true, settings: true, gitSource: { include: { githubApp: true, gitlabApp: true } }, secrets: true } })

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


export async function configureGitRepository({ id, repository, branch, projectId, webhookToken }) {
    try {
        if (webhookToken) {
            const encryptedWebhookToken = encrypt(webhookToken)
            await prisma.application.update({ where: { id }, data: { repository, branch, projectId, gitSource: { update: { gitlabApp: { update: { webhookToken: encryptedWebhookToken } } } } } })
        } else {
            await prisma.application.update({ where: { id }, data: { repository, branch, projectId } })
        }
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

export async function configureApplication({ id, name, teamId, domain, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory }) {
    try {
        let application = await prisma.application.findFirst({ where: { id, teams: { every: { id: teamId } } } })
        if (application.domain !== domain && !application.oldDomain) {
            application = await prisma.application.update({ where: { id }, data: { domain, oldDomain: application.domain, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory, name } })
        } else {
            application = await prisma.application.update({ where: { id }, data: { domain, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory, name } })
        }
        return { status: 201, body: { application } }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function setApplicationSettings({ id, debug, previews, forceSSL, forceSSLChanged }) {
    try {
        const application = await prisma.application.update({ where: { id }, data: { settings: { update: { debug, previews, forceSSL } } }, include: { destinationDocker: true } })
        const { destinationDocker, domain } = application
        if (forceSSLChanged) {
            await letsEncrypt({ destinationDocker, domain, id, forceSSLChanged })
        }
        return { status: 201 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function createBuild({ id, applicationId, destinationDockerId, gitSourceId, githubAppId, gitlabAppId, type }) {
    try {
        return await prisma.build.create({
            data: {
                id,
                applicationId,
                destinationDockerId,
                gitSourceId,
                githubAppId,
                gitlabAppId,
                status: 'running',
                type,
            }
        })
    } catch (e) {
        throw PrismaErrorHandler(e)
    }

}