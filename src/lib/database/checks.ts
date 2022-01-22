import { getDomain } from "$lib/common"
import { prisma, PrismaErrorHandler } from "./common"

export async function isBranchAlreadyUsed({ repository, branch, id }) {
    try {
        const application = await prisma.application.findUnique({ where: { id }, include: { gitSource: true } })

        const found = await prisma.application.findFirst({ where: { branch, repository, gitSource: { type: application.gitSource.type } }, rejectOnNotFound: false })

        if (found) {
            return { status: 200 }
        }
        return { status: 404 }
    } catch (err) {
        throw PrismaErrorHandler(err)
    }
}

export async function isDockerNetworkExists({ network }) {
    try {
        const found = await prisma.destinationDocker.findFirst({ where: { network }, rejectOnNotFound: false })
        if (found) {
            return { status: 200 }
        }
        return { status: 404 }
    } catch (err) {
        throw PrismaErrorHandler(err)
    }
}



export async function isSecretExists({ id, name }) {
    try {
        const found = await prisma.secret.findFirst({ where: { name, applicationId: id } })
        return {
            status: 200
        }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function isDomainConfigured({ id, fqdn }) {
    const domain = getDomain(fqdn)
    try {
        const foundApplication = await prisma.application.findMany({ where: { fqdn: { endsWith: domain }, id: { not: id } }, select: { fqdn: true } })
        const foundService = await prisma.service.findMany({ where: { fqdn: { endsWith: domain }, id: { not: id } }, select: { fqdn: true } })

        if (foundApplication.length > 0 || foundService.length > 0) {
            return {
                status: 500,
                body: {
                    message: "Domain already configured!"
                }
            }
        }
        return {
            status: 200
        }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}