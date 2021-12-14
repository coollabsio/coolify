import { dev } from '$app/env'
import * as Prisma from '@prisma/client'
import { default as ProdPrisma } from '@prisma/client'

import forge from 'node-forge'


let { PrismaClient } = Prisma
let P = Prisma.Prisma
if (!dev) {
    PrismaClient = ProdPrisma.PrismaClient
    P = ProdPrisma.Prisma
}
let prismaOptions = {
    rejectOnNotFound: true,
}
if (dev) {
    prismaOptions = {
        errorFormat: 'pretty',
        rejectOnNotFound: true,
        log: [{
            emit: 'event',
            level: 'query',
        }]
    }
}
export const prisma = new PrismaClient(prismaOptions)

export function PrismaErrorHandler(e) {
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
export async function generateSshKeyPair(): Promise<{ publicKey: string, privateKey: string }> {
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


