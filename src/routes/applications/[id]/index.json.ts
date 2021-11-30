import { selectTeam } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import jsonwebtoken from 'jsonwebtoken'
import type { Locals } from 'src/global';

export const get: RequestHandler = async (request) => {
    const teamId = selectTeam(request)
    let githubToken = null;
    let gitlabToken = null;
    const { id } = request.params
    const application = await db.getApplication({ id, teamId })

    if (application.status) {
        return {
            ...application
        };
    }
    if (application.gitSource?.type === 'github') {
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
    } else if (application.gitSource?.type === 'gitlab') {
        if (request.headers.cookie) {
            gitlabToken = request.headers.cookie?.split(';').map(s => s.trim()).find(s => s.startsWith('gitlabToken='))?.split('=')[1]
        }
    }


    return {
        body: {
            githubToken,
            gitlabToken,
            application
        }
    };

}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const teamId = selectTeam(request)
    const { id } = request.params
    const domain = request.body.get('domain') || null
    const port = Number(request.body.get('port')) || null
    const installCommand = request.body.get('installCommand') || null
    const buildCommand = request.body.get('buildCommand') || null
    const startCommand = request.body.get('startCommand') || null
    const baseDirectory = request.body.get('baseDirectory') || null
    const publishDirectory = request.body.get('publishDirectory') || null

    return await db.configureApplication({ id, teamId, domain, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory })

}