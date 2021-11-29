import { dev } from '$app/env'
import * as Prisma from '@prisma/client'
import { default as ProdPrisma } from '@prisma/client'
import { decrypt, encrypt } from './crypto'
import bcrypt from 'bcrypt';
import jsonwebtoken from 'jsonwebtoken'
import cuid from 'cuid';
import forge from 'node-forge'

const { SECRET_KEY } = process.env;
const secretKey = SECRET_KEY;

let { PrismaClient } = Prisma
let P = Prisma.Prisma
if (!dev) {
    PrismaClient = ProdPrisma.PrismaClient
    P = ProdPrisma.Prisma
}
let prismaOptions = {}
if (dev) {
    prismaOptions = {
        errorFormat: 'pretty',
        log: [{
            emit: 'event',
            level: 'query',
        }]
    }
}
export const prisma = new PrismaClient(prismaOptions)

function PrismaErrorHandler(e) {

    const payload = {
        status: 500,
        body: {
            message: 'Ooops, something is not okay, are you okay?',
            error: e.message
        }
    }
    if (e.name === 'NotFoundError') {
        payload.status = 404
    }
    if (e instanceof P.PrismaClientKnownRequestError) {
        if (e.code === 'P2002') {
            payload.body.message = "Already exists. Choose another name."
        }
    }
    console.error(e)
    return payload
}
async function generateSshKeyPair(): Promise<{ publicKey: string, privateKey: string }> {
    return await new Promise(async (resolve, reject) => {
        forge.pki.rsa.generateKeyPair({ bits: 4096, workers: -1 }, function (err, keys) {
            if (keys) {
                resolve({
                    publicKey: forge.ssh.publicKeyToOpenSSH(keys.publicKey),
                    privateKey: forge.ssh.privateKeyToOpenSSH(keys.privateKey)
                })
            }
            else { reject(keys) }
        });
    })
}

// DB functions
export async function getUser({ uid }) {
    try {
        await prisma.user.findUnique({ where: { uid }, rejectOnNotFound: true })
        return { status: 200 }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}
export async function listApplications() {
    return await prisma.application.findMany()
}

export async function newApplication({ name }) {
    try {
        const app = await prisma.application.create({ data: { name: name } })
        return { status: 201, body: { id: app.id } }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}

export async function getApplication({ id }) {
    try {
        let body = await prisma.application.findUnique({ where: { id }, include: { destinationDocker: true, gitSource: { include: { githubApp: true, gitlabApp: true } } } })


        if (body.gitSource?.githubApp?.clientSecret) body.gitSource.githubApp.clientSecret = decrypt(body.gitSource.githubApp.clientSecret)
        if (body.gitSource?.githubApp?.webhookSecret) body.gitSource.githubApp.webhookSecret = decrypt(body.gitSource.githubApp.webhookSecret)
        if (body.gitSource?.githubApp?.privateKey) body.gitSource.githubApp.privateKey = decrypt(body.gitSource.githubApp.privateKey)


        if (body?.gitSource?.gitlabApp?.appSecret) body.gitSource.gitlabApp.appSecret = decrypt(body.gitSource.gitlabApp.appSecret)

        return { ...body }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}

export async function listSources() {
    try {
        const body = await prisma.gitSource.findMany({ include: { githubApp: true, gitlabApp: true } })
        return [...body]
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}

export async function newSource({ name, type, htmlUrl, apiUrl, organization }) {
    try {
        const source = await prisma.gitSource.create({ data: { name, type, htmlUrl, apiUrl, organization } })
        return { status: 201, body: { id: source.id } }
    } catch (e) {
        return PrismaErrorHandler(e)
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
        return PrismaErrorHandler(e)
    }
}

export async function getSource({ id }) {
    try {
        let body = await prisma.gitSource.findUnique({ where: { id }, include: { githubApp: true, gitlabApp: true } })
        if (body?.githubApp?.clientSecret) body.githubApp.clientSecret = decrypt(body.githubApp.clientSecret)
        if (body?.githubApp?.webhookSecret) body.githubApp.webhookSecret = decrypt(body.githubApp.webhookSecret)
        if (body?.githubApp?.privateKey) body.githubApp.privateKey = decrypt(body.githubApp.privateKey)

        if (body?.gitlabApp?.appSecret) body.gitlabApp.appSecret = decrypt(body.gitlabApp.appSecret)
        return { ...body }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}
export async function addSource({ id, appId, name, groupName, appSecret }) {
    try {
        const encrptedAppSecret = encrypt(appSecret)
        const source = await prisma.gitlabApp.create({ data: { appId, name, groupName, appSecret: encrptedAppSecret, gitSource: { connect: { id } } } })
        return { status: 201, body: { id: source.id } }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}

export async function configureGitsource({ id, gitSourceId }) {
    try {
        await prisma.application.update({ where: { id }, data: { gitSource: { connect: { id: gitSourceId } } } })
        return { status: 201 }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}

export async function listDestinations() {
    try {
        const body = await prisma.destinationDocker.findMany()
        return [...body]
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}

export async function configureDestination({ id, destinationId }) {
    try {
        await prisma.application.update({ where: { id }, data: { destinationDocker: { connect: { id: destinationId } } } })
        return { status: 201 }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}
export async function updateDestination({ id, name, isSwarm, engine, network }) {
    try {
        await prisma.destinationDocker.update({ where: { id }, data: { name, isSwarm, engine, network } })
        return { status: 200 }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}


export async function newDestination({ name, isSwarm, engine, network }) {
    try {
        const destination = await prisma.destinationDocker.create({ data: { name, isSwarm, engine, network } })
        return {
            status: 201, body: { id: destination.id }
        }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}
export async function removeDestination({ id }) {
    try {
        await prisma.destinationDocker.delete({ where: { id } })
        return { status: 200 }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}

export async function getDestination({ id }) {
    try {
        const body = await prisma.destinationDocker.findUnique({ where: { id } })
        return { ...body }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}

export async function createGithubApp({ id, client_id, slug, client_secret, pem, webhook_secret, state }) {
    try {
        await prisma.githubApp.create({
            data: {
                appId: id,
                name: slug,
                clientId: client_id,
                clientSecret: client_secret,
                webhookSecret: webhook_secret,
                privateKey: pem,
                gitSource: { connect: { id: state } }
            }
        })
        return { status: 201 }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}
export async function addInstallation({ gitSourceId, installation_id }) {
    try {
        const source = await prisma.gitSource.findUnique({ where: { id: gitSourceId }, include: { githubApp: true } })
        await prisma.githubApp.update({ where: { id: source.githubAppId }, data: { installationId: Number(installation_id) } })
        return { status: 201 }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}

export async function isBranchAlreadyUsed({ repository, branch, id }) {
    try {
        const application = await prisma.application.findUnique({ where: { id }, include: { gitSource: true } })
        const found = await prisma.application.findFirst({ where: { branch, repository, gitSource: { type: application.gitSource.type } } })
        if (found) {
            return { status: 200 }
        }
        return { status: 404 }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}

export async function configureGitRepository({ id, repository, branch, projectId }) {
    try {
        await prisma.application.update({ where: { id }, data: { repository, branch, projectId } })
        return { status: 201 }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}

export async function configureBuildPack({ id, buildPack }) {
    try {
        await prisma.application.update({ where: { id }, data: { buildPack } })
        return { status: 201 }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}

export async function configureApplication({ id, domain, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory }) {
    try {
        let application = await prisma.application.findUnique({ where: { id } })
        if (application.domain !== domain && !application.oldDomain) {
            application = await prisma.application.update({ where: { id }, data: { domain, oldDomain: application.domain, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory } })
        } else {
            application = await prisma.application.update({ where: { id }, data: { domain, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory } })
        }
        return { status: 201, body: { application } }
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}

export async function listLogs({ buildId, last = 0 }) {
    try {
        const body = await prisma.buildLog.findMany({ where: { buildId, time: { gt: last } }, orderBy: { time: 'asc' } })
        return [...body]
    } catch (e) {
        return PrismaErrorHandler(e)
    }
}

export async function login({ email, password }) {
    const saltRounds = 15;
    const users = await prisma.user.count()
    const userFound = await prisma.user.findUnique({ where: { email }, include: { teams: { select: { assignedBy: true, assignedAt: true, teamId: true } } } })
    
    // Registration disabled if database is not seeded properly
    const { value: isRegistrationEnabled = 'false' } = await prisma.setting.findUnique({ where: { name: 'isRegistrationEnabled' }, select: { value: true } }) || {}

    let uid = cuid()
    let teams = []
    if (userFound) {
        if (userFound.type === 'email') {
            const passwordMatch = await bcrypt.compare(password, userFound.password)
            if (!passwordMatch) {
                return {
                    status: 500,
                    body: {
                        message: 'Wrong password or email address.'
                    }
                };
            }
            uid = userFound.uid
            teams = userFound.teams
        }
    } else {
        // If registration disabled, return 403
        if (isRegistrationEnabled === 'false') {
            return {
                status: 403,
                body: {
                    message: 'Registration disabled by administrator.'
                }
            }
        }


        const hashedPassword = await bcrypt.hash(password, saltRounds)
        const user = await prisma.user.create({
            data: {
                email,
                password: hashedPassword,
                uid,
                type: 'email',
                teams: {
                    create: {
                        assignedBy: uid,
                        team: {
                            create: {
                                teamId: uid
                            }
                        }
                    }
                }
            }, include: { teams: { select: { assignedBy: true, assignedAt: true, teamId: true } } }
        })
        teams = user.teams
    }
    // Disable registration if we are registering the first user.
    if (users === 0) {
        await prisma.setting.update({ where: { name: 'isRegistrationEnabled' }, data: { value: 'false' } })
    }

    const token = jsonwebtoken.sign({}, secretKey, {
        expiresIn: 15778800,
        algorithm: 'HS256',
        audience: 'coolify',
        issuer: 'coolify',
        jwtid: uid,
        subject: `User:${uid}`,
        notBefore: -1000
    });
    return {
        status: 200,
        body: {
            isLoggedIn: true,
            uid,
            teams,
            token
        }
    }
}

export async function getUniqueGithubApp({ githubAppId }) {
    try {
        let body = await prisma.githubApp.findUnique({ where: { id: githubAppId } })
        if (body.privateKey) body.privateKey = decrypt(body.privateKey)
        return { ...body }
    } catch (e) {
        return PrismaErrorHandler(e)
    }

}

export async function updateDeployKey({ id, deployKeyId }) {
    try {
        const application = await prisma.application.findUnique({ where: { id }, include: { gitSource: { include: { gitlabApp: true } } } })
        await prisma.gitlabApp.update({ where: { id: application.gitSource.gitlabApp.id }, data: { deployKeyId } })
        return { status: 201 }
    } catch (e) {
        return PrismaErrorHandler(e)
    }

}
export async function generateSshKey({ id }) {
    try {
        const application = await prisma.application.findUnique({ where: { id }, include: { gitSource: { include: { gitlabApp: true } } } })
        if (!application.gitSource?.gitlabApp?.privateSshKey) {
            const keys = await generateSshKeyPair()
            const encryptedPrivateKey = encrypt(keys.privateKey)
            await prisma.gitlabApp.update({ where: { id: application.gitSource.gitlabApp.id }, data: { privateSshKey: encryptedPrivateKey } })
            return { status: 201, body: { publicKey: keys.publicKey } }
        } else {
            return { status: 200 }
        }

    } catch (e) {
        return PrismaErrorHandler(e)
    }

}

