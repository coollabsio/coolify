import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const email = request.body.get('email')
    const password = request.body.get('password')
    const response = await db.login({ email, password })
    if (response.status === 200) {
        const { body } = response
        request.locals.session.data = body
    } else {
        return {
            status: response.status,
            body: {
                message: response.body.message
            }
        }
    }
    return response
    // if (response.body.defaultTeam) {
    //     return {
    //         ...response,
    //         headers: {
    //             ...response.headers,
    //             'Set-Cookie': [`teamId=${response.body.defaultTeam}`]
    //         }
    //     }
    // } else {
    //     return response

    // }
}

export const get: RequestHandler<Locals, FormData> = async (request) => {
    const { userId } = await getUserDetails(request, false)
    if (!userId) {
        return {
            status: 401
        }
    }
    return await db.getUser({ userId })
}