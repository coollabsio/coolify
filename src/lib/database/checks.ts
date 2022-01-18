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

export async function isDomainConfigured({ id, domain }) {
    try {
        const applicationDomains = await prisma.application.findMany({ where: { domain: { not: null }, id: { not: id } }, select: { domain: true } })
        const serviceDomains = await prisma.service.findMany({ where: { domain: { not: null }, id: { not: id } }, select: { domain: true } })
        let foundApplicationDomain = null
        let foundServiceDomain = null
        if (applicationDomains.length > 0) {
            foundApplicationDomain = applicationDomains.find(applicationDomain => applicationDomain.domain.replace('http://', '').replace('https://', '') === domain)

        }
        if (serviceDomains.length > 0) {
            foundServiceDomain = serviceDomains.find(serviceDomain => serviceDomain.domain.replace('http://', '').replace('https://', '') === domain)

        }
        if (foundApplicationDomain || foundServiceDomain) {
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