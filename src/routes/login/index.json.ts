import { selectTeam } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import type { Locals } from 'src/global';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const email = request.body.get('email')
    const password = request.body.get('password')
    const response = await db.login({ email, password })
    if (response.status === 200) {
        const { body } = response
        request.locals.session.data = body
    }
    return {
        ...response,
        headers: {
            ...response.headers,
            'Set-Cookie': [`selectedTeam=${selectTeam(request)}`]
        }
    }
}

export const get: RequestHandler<Locals, FormData> = async (request) => {
    const uid = request.query.get('uid')
    if (!uid) {
        return {
            status: 401
        }
    }
    return await db.getUser({ uid })

}