import dotEnvExtended from 'dotenv-extended';
dotEnvExtended.load();
import type { GetSession } from "@sveltejs/kit";
import { handleSession } from "svelte-kit-cookie-session";
import { getUserDetails, isTeamIdTokenAvailable, sentry } from '$lib/common';

export const handle = handleSession(
    {
        secret: process.env['SECRET_KEY'],
        expires: 30
    },
    async function ({ request, resolve }) {
        const isTeamIdTokenAvailableResult = isTeamIdTokenAvailable(request);

        if (Object.keys(request.locals.session.data).length > 0) {
            const { permission, teamId } = await getUserDetails(request, false);
            request.locals.user = {
                teamId,
                permission
            }
        }


        const response = await resolve(request);

        let responseWithCookie = response

        if (isTeamIdTokenAvailableResult) {
            responseWithCookie = {
                ...response,
                headers: {
                    ...response.headers,
                    'Set-Cookie': [`teamId=${isTeamIdTokenAvailableResult}`]
                }
            }
        }
        return responseWithCookie
        // if (!response.body || !response.headers) {
        //     return response;
        // }
        // return response;
    }
);


export const getSession: GetSession<Locals> = function (request) {

    const payload = {
        uid: request.locals.session.data?.uid || null,
        teamId: request.locals.user?.teamId || null,
        permission: request.locals.user?.permission
    }
    return payload
};

export async function handleError({ error, request }) {
    sentry.captureException(error, { request });
}