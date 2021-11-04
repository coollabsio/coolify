import * as db from '$lib/database';
import type Prisma from '@prisma/client'
import type { RequestHandler } from '@sveltejs/kit';
import jsonwebtoken from 'jsonwebtoken'
export const get: RequestHandler = async (request) => {
    let githubToken = null;
    const { id } = request.params
    const application = await db.getApplication({ id })
    if (application.status) {
        return {
            ...application
        };
    }
    if (application?.gitSource?.githubApp) {
        const payload = {
            iat: Math.round(new Date().getTime() / 1000),
            exp: Math.round(new Date().getTime() / 1000 + 60),
            iss: application.gitSource.githubApp.appId,
        }
        githubToken = jsonwebtoken.sign(payload, application.gitSource.githubApp.privateKey, {
            algorithm: 'RS256',
        })
    }

    return {
        body: {
            githubToken,
            application
        }
    };

}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { id } = request.params
    const domain = request.body.get('domain') || null
    const port = Number(request.body.get('port')) || null
    const installCommand = request.body.get('installCommand') || null
    const buildCommand = request.body.get('buildCommand') || null
    const startCommand = request.body.get('startCommand') || null
    const baseDirectory = request.body.get('baseDirectory') || null
    const publishDirectory = request.body.get('publishDirectory') || null

    return await db.configureApplication({ id, domain, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory })

}