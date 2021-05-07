
import { deleteCookies } from '$lib/api/common';
import type { Request } from '@sveltejs/kit';

export async function post(request: Request) {
    return {
        status: 200,
        headers: {
            'set-cookie': [
              ...deleteCookies
            ]
        },
        body: { success: true }
    };
}
