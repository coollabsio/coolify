import { encrypt } from "$lib/crypto"
import { prisma, PrismaErrorHandler } from "./common"

export async function listSecrets({ applicationId }) {
    try {
        const body = await prisma.secret.findMany({ where: { applicationId }, orderBy: { createdAt: 'desc' }, select: { id: true, createdAt: true, name: true, isBuildSecret: true } })
        return [...body]
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function createSecret({ id, name, value, isBuildSecret }) {
    try {
        value = encrypt(value)
        await prisma.secret.create({ data: { name, value, isBuildSecret, application: { connect: { id } } } })
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function removeSecret({ id, name }) {
    try {
        await prisma.secret.deleteMany({ where: { applicationId: id, name } })
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}