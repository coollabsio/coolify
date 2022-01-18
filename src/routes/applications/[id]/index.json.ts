import { getTeam, getUserDetails } from '$lib/common';
import { getGithubToken } from '$lib/components/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import jsonwebtoken from 'jsonwebtoken'

export const get: RequestHandler = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }
    
    let githubToken = null;
    let gitlabToken = null;
    let ghToken = null;
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
            ghToken = await getGithubToken({ apiUrl: application.gitSource.apiUrl, application, githubToken })
        }
    } else if (application.gitSource?.type === 'gitlab') {
        if (request.headers.cookie) {
            gitlabToken = request.headers.cookie?.split(';').map(s => s.trim()).find(s => s.startsWith('gitlabToken='))?.split('=')[1]
        }
    }


    return {
        body: {
            ghToken,
            githubToken,
            gitlabToken,
            application
        }
    };

}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const name = request.body.get('name') || undefined
    const domain = request.body.get('domain').toLocaleLowerCase() || undefined
    const port = Number(request.body.get('port')) || undefined
    const installCommand = request.body.get('installCommand') || undefined
    const buildCommand = request.body.get('buildCommand') || undefined
    const startCommand = request.body.get('startCommand') || undefined
    const baseDirectory = request.body.get('baseDirectory') || undefined
    const publishDirectory = request.body.get('publishDirectory') || undefined

    try {
        return await db.configureApplication({ id, name, teamId, domain, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory })
    } catch (err) {
        return err
    }

}