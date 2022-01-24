import { getTeam, getUserDetails } from '$lib/common';
import { getGithubToken } from '$lib/components/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import jsonwebtoken from 'jsonwebtoken'

export const get: RequestHandler = async (event) => {
    const { teamId, status, body } = await getUserDetails(event);
    if (status === 401) return { status, body }

    let githubToken = null;
    let ghToken = null;
    const { id } = event.params
    try {
        const application = await db.getApplication({ id, teamId })
        const { gitSource } = application
        if (gitSource?.type === 'github' && gitSource?.githubApp) {
            const payload = {
                iat: Math.round(new Date().getTime() / 1000),
                exp: Math.round(new Date().getTime() / 1000 + 60),
                iss: gitSource.githubApp.appId,
            }
            githubToken = jsonwebtoken.sign(payload, gitSource.githubApp.privateKey, {
                algorithm: 'RS256',
            })
            ghToken = await getGithubToken({ apiUrl: gitSource.apiUrl, application, githubToken })
        }
        return {
            body: {
                ghToken,
                githubToken,
                application
            }
        };
    } catch (error) {
        console.log(error)
        return PrismaErrorHandler(error)
    }

    // if (application.status) {
    //     return {
    //         ...application
    //     };
    // }
    // if (application.gitSource?.type === 'github') {
    //     if (application?.gitSource?.githubApp) {
    //         const payload = {
    //             iat: Math.round(new Date().getTime() / 1000),
    //             exp: Math.round(new Date().getTime() / 1000 + 60),
    //             iss: application.gitSource.githubApp.appId,
    //         }
    //         githubToken = jsonwebtoken.sign(payload, application.gitSource.githubApp.privateKey, {
    //             algorithm: 'RS256',
    //         })
    //         ghToken = await getGithubToken({ apiUrl: application.gitSource.apiUrl, application, githubToken })
    //     }
    // } else if (application.gitSource?.type === 'gitlab') {
    //     if (request.headers.cookie) {
    //         gitlabToken = request.headers.cookie?.split(';').map(s => s.trim()).find(s => s.startsWith('gitlabToken='))?.split('=')[1]
    //     }
    // }


    return {
        body: {
            // ghToken,
            // githubToken,
            // gitlabToken,
            // application
        }
    };

}

export const post: RequestHandler<Locals> = async (event) => {
    const { teamId, status, body } = await getUserDetails(event);
    if (status === 401) return { status, body }

    const { id } = event.params
    let { name, fqdn, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory } = await event.request.json()
    port = Number(port)

    console.log({ name, fqdn, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory })

    try {
        const application = await db.configureApplication({ id, name, teamId, fqdn, port, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory })
        return { status: 201, body: { application } }

    } catch (error) {
        return PrismaErrorHandler(error)
    }

}