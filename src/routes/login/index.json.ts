import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const email = request.body.get('email')
    const password = request.body.get('password')
    const response = await db.login({ email, password })
    if (response.status === 200) {
        const { body } = response
        request.locals.session.data = body
    }
    return response
}
