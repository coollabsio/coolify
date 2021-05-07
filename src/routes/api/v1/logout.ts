
import type { Request } from '@sveltejs/kit';

export async function post(request: Request) {
    return {
        status: 200,
        headers: {
            'set-cookie': [
                `coolToken=deleted; Path=/; HttpOnly; expires=Thu, 01 Jan 1970 00:00:00 GMT`,
                `ghToken=deleted; Path=/; HttpOnly; expires=Thu, 01 Jan 1970 00:00:00 GMT`
            ]
        },
        body: { success: true }
    };
}
