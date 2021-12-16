import dotEnvExtended from 'dotenv-extended';
dotEnvExtended.load();
import type { GetSession } from "@sveltejs/kit";
import { handleSession } from "svelte-kit-cookie-session";
import { getUserDetails, isTeamIdTokenAvailable, sentry } from '$lib/common';
import { version } from '$lib/common';

// EDGE case: Same COOLIFY_SECRET_KEY, but different database. Permission not found.
export const handle = handleSession(
    {
        secret: process.env['COOLIFY_SECRET_KEY'],
        expires: 30
    },
    async function ({ request, resolve }) {
        let isTeamIdTokenAvailableResult = null
        if (Object.keys(request.locals.session.data).length > 0) {
            isTeamIdTokenAvailableResult = isTeamIdTokenAvailable(request)

            const { permission, teamId } = await getUserDetails(request, false);
            request.locals.user = {
                teamId,
                permission,
                isAdmin: permission === 'admin' || permission === 'owner'
            }
        }


        const response = await resolve(request);

        let responseWithCookie = response

        // This check needed for switching team with HttpOnly cookie (see /src/routes/index.json.ts)
        if (isTeamIdTokenAvailableResult && request.path !== '/index.json' && request.method !== 'POST' && request.path !== '/logout.json') {
            responseWithCookie = {
                ...response,
                headers: {
                    ...response.headers,
                    'Set-Cookie': [`teamId=${isTeamIdTokenAvailableResult};  HttpOnly; Path=/; Max-Age=15778800;`]
                }
            }
        }
        return responseWithCookie
    }
);


export const getSession: GetSession<Locals> = function (request) {
    const payload = {
        version,
        uid: request.locals.session.data?.uid || null,
        teamId: request.locals.user?.teamId || null,
        permission: request.locals.user?.permission,
        isAdmin: request.locals.user?.isAdmin || false
    }
    return payload
};

export async function handleError({ error, request }) {
    sentry.captureException(error, { request });
}