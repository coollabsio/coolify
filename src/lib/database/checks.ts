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
    console.log(fqdn)
    try {
        const applicationDomains = await prisma.application.findMany({ where: { fqdn: { not: null }, id: { not: id } }, select: { fqdn: true } })
        const serviceDomains = await prisma.service.findMany({ where: { fqdn: { not: null }, id: { not: id } }, select: { fqdn: true } })
        let foundApplicationDomain = null
        let foundServiceDomain = null
        if (applicationDomains.length > 0) {
            foundApplicationDomain = applicationDomains.find(applicationDomain => applicationDomain.fqdn === fqdn)

        }
        if (serviceDomains.length > 0) {
            foundServiceDomain = serviceDomains.find(serviceDomain => serviceDomain.fqdn === fqdn)

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