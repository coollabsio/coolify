import { encrypt } from "$lib/crypto"
import { generateSshKeyPair, prisma, PrismaErrorHandler } from "./common"

export async function updateDeployKey({ id, deployKeyId }) {
    const application = await prisma.application.findUnique({ where: { id }, include: { gitSource: { include: { gitlabApp: true } } } })
    return await prisma.gitlabApp.update({ where: { id: application.gitSource.gitlabApp.id }, data: { deployKeyId } })
}
export async function generateSshKey({ id }) {
    const application = await prisma.application.findUnique({ where: { id }, include: { gitSource: { include: { gitlabApp: true } } } })
    if (!application.gitSource?.gitlabApp?.privateSshKey) {
        const keys = await generateSshKeyPair()
        const encryptedPrivateKey = encrypt(keys.privateKey)
        await prisma.gitlabApp.update({ where: { id: application.gitSource.gitlabApp.id }, data: { privateSshKey: encryptedPrivateKey } })
        return { status: 201, body: { publicKey: keys.publicKey } }
    } else {
        return { status: 200 }
    }
}