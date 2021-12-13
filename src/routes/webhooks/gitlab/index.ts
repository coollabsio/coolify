import { getTeam } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import got from 'got';

export const get: RequestHandler = async (request) => {
    const teamId = getTeam(request)
    const tokenUrl = 'https://gitlab.com/oauth/token'
    const code = request.query.get('code')
    const state = request.query.get('state')

    try {
        const application = await db.getApplication({ id: state, teamId })
        const { appId, appSecret } = application.gitSource.gitlabApp
        // TODO must not be localhost
        const { access_token } = await got.post(tokenUrl, {
            searchParams: {
                client_id: appId,
                client_secret: appSecret,
                code,
                state,
                grant_type: 'authorization_code',
                redirect_uri: `http://${request.headers.host}/webhooks/gitlab`
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