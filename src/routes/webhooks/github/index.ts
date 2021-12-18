import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

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
    const code = request.query.get('code')
    const state = request.query.get('state')
    try {
        const response = await fetch(`https://api.github.com/app-manifests/${code}/conversions`, { method: 'POST' })
        if (!response.ok) {
            return {
                status: 500,
                body: { ...await response.json() }
            }
        }

        const { id, client_id, slug, client_secret, pem, webhook_secret } = await response.json()
        const dbresponse = await db.createGithubApp({ id, client_id, slug, client_secret, pem, webhook_secret, state })
        if (dbresponse.status !== 201) {
            return {
                ...dbresponse
            }
        }
        return {
            status: 302,
            headers: { Location: `/sources/${state}` }
        }
    } catch (err) {
        return err
    }
}