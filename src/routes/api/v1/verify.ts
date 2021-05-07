
import { verifyUserId } from '$lib/api/applications/common';
import type { Request } from '@sveltejs/kit';
import * as cookie from 'cookie';

export async function post(request: Request) {
    const { coolToken } = cookie.parse(request.headers.cookie || '');
    try {
        await verifyUserId(coolToken)
        return {
            status: 200,
            body: { success: true }
        };
    } catch (error) {
        return {
            status: 301,
            headers: {
                location:'/',
                'set-cookie': [
                    `coolToken=deleted; Path=/; HttpOnly; expires=Thu, 01 Jan 1970 00:00:00 GMT`,
                    `ghToken=deleted; Path=/; HttpOnly; expires=Thu, 01 Jan 1970 00:00:00 GMT`
                ]
            },
            body: { error: 'Unauthorized' }
        };
    }


}
