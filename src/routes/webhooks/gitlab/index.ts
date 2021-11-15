import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import got from 'got';

export const get: RequestHandler = async (request) => {
    const tokenUrl = 'https://gitlab.com/oauth/token'
    const code = request.query.get('code')
    const state = request.query.get('state')
    try {
        const { gitlabApp } = await db.getSource({ id: 'ckvwbe9dq0000a4uykkp95vyw' })
        const { appId, appSecret } = gitlabApp
        console.log(appId, appSecret, code, state)
        // TODO must not be localhost
        const { access_token } = await got.post(tokenUrl, {
            searchParams: {
                client_id: appId,
                client_secret: appSecret,
                code,
                state,
                grant_type: 'authorization_code',
                redirect_uri: 'http://localhost:3000/webhooks/gitlab'
            }
        }).json()

        // TODO: Flow
        // https://gitlab.com/api/v4/user
        // https://gitlab.com/api/v4/groups?per_page=5000
        // https://gitlab.com/api/v4/users/andrasbacsai/projects?min_access_level=40&page=1&per_page=25 or https://gitlab.com/api/v4/groups/3086145/projects?page=1&per_page=25
        // https://gitlab.com/api/v4/projects/7260661/repository/branches?per_page=100&page=1

        // https://gitlab.com/api/v4/projects/coollabsio%2FcoolLabs.io-frontend-v1/repository/tree?per_page=100&ref=master
        // https://gitlab.com/api/v4/projects/7260661/repository/files/package.json?ref=master

        // https://gitlab.com/api/v4/projects/7260661/deploy_keys - Create deploy keys with ssh-keys?
        // https://gitlab.com/api/v4/projects/7260661/hooks - set webhook for project

    } catch (err) {
        throw new Error(err)
    }
    return {
        status: 200,
    }
}