import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals> = async ({ request, locals }) => {
    const data = await request.formData()
    const email = data.get('email')
    const password = data.get('password')
    try {
        const response = await db.login({ email, password })
        if (response.status === 200) {
            const { body } = response
            locals.session.data = body
        } else {
            return {
                status: response.status,
                body: {
                    message: response.body.message
                }
            }
        }
        return response
    } catch (err) {
        return err
    }

}

export const get: RequestHandler<Locals, FormData> = async (request) => {
    const { userId } = await getUserDetails(request, false)
    if (!userId) {
        return {
            status: 401
        }
    }
    try {
        return await db.getUser({ userId })
    } catch (err) {
        console.log(err)
        return err
    }
}