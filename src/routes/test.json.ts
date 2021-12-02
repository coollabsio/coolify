import { getUserDetails, sentry } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
	const { permission, teamId, status, body } = await getUserDetails(request, false)
	if (status === 401) {
        sentry.captureException(body.message, { request });
        return { status, body }
    } 

    return {
        body: {
            permission,
            teamId,
            status
        }
    };
}
