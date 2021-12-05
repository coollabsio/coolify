import { encrypt } from "$lib/crypto"
import { generateSshKeyPair, prisma, PrismaErrorHandler } from "./common"

export async function updateDeployKey({ id, deployKeyId }) {
    try {
        const application = await prisma.application.findUnique({ where: { id }, include: { gitSource: { include: { gitlabApp: true } } } })
        await prisma.gitlabApp.update({ where: { id: application.gitSource.gitlabApp.id }, data: { deployKeyId } })
        return { status: 201 }
    } catch (e) {
        throw PrismaErrorHandler(e)
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
        throw PrismaErrorHandler(e)
    }

}