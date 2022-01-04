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
    const gitSourceId = request.url.searchParams.get('gitSourceId')
    const installation_id = request.url.searchParams.get('installation_id')

    const dbresponse = await db.addInstallation({ gitSourceId, installation_id })
    if (dbresponse.status !== 201) {
        return {
            ...dbresponse
        }
    }
    return {
        status: 302,
        headers: { Location: `/webhooks/success` }
    }
}