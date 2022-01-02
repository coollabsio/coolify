import { dev } from '$app/env';
import { getTeam } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import got from 'got';

export const options = async () => {
    return {
        status: 200,
        headers: {
            'Access-Control-Allow-Origin': '*',
            'Access-Control-Allow-Headers': 'Content-Type, Authorization',
            'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',

        }
    }
}

export const get: RequestHandler = async (request) => {
    const teamId = getTeam(request)
    const code = request.url.searchParams.get('code')
    const state = request.url.searchParams.get('state')

    try {
        const application = await db.getApplication({ id: state, teamId })
        const { appId, appSecret } = application.gitSource.gitlabApp
        const { htmlUrl } = application.gitSource
        const { access_token } = await got.post(`${htmlUrl}/oauth/token`, {
            searchParams: {
                client_id: appId,
                client_secret: appSecret,
                code,
                state,
                grant_type: 'authorization_code',
                redirect_uri: `${request.url.origin}/webhooks/gitlab`
            }
        }).json()

        return {
            status: 302,
            headers: {
                Location: `/webhooks/success`, "Set-Cookie": [
                    `gitlabToken=${access_token}; HttpOnly; Path=/; Max-Age=15778800;`
                ]
            }
        }
    } catch (err) {
        return err
    }

}