import { prisma, PrismaErrorHandler } from "./common"

export async function isBranchAlreadyUsed({ repository, branch, id }) {
    try {
        const application = await prisma.application.findUnique({ where: { id }, include: { gitSource: true } })
        const found = await prisma.application.findFirst({ where: { branch, repository, gitSource: { type: application.gitSource.type } } })
        if (found) {
            return { status: 200 }
        }
        return { status: 404 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}