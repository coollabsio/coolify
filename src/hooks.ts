import dotEnvExtended from 'dotenv-extended';
dotEnvExtended.load();
import type { GetSession } from "@sveltejs/kit";
import { handleSession } from "svelte-kit-cookie-session";
import { getUserDetails, isTeamIdTokenAvailable, sentry } from '$lib/common';
import { version } from '$lib/common';
import Cookie from 'cookie'
// EDGE case: Same COOLIFY_SECRET_KEY, but different database. Permission not found.
export const handle = handleSession(
    {
        secret: process.env['COOLIFY_SECRET_KEY'],
        expires: 30
    },
    async function ({ event, resolve }) {
        console.log(event)
        /// TODO: Here I am
        let isTeamIdTokenAvailableResult = null
        if (Object.keys(event.locals.session.data).length > 0) {
            const cookies: Cookies = Cookie.parse(event.request.headers.get('cookie'))
            if (cookies.teamId) {
            console.log(cookies)
        //     isTeamIdTokenAvailableResult = isTeamIdTokenAvailable(event)
        //     const { permission, teamId } = await getUserDetails(event, false);
        //     event.locals.user = {
        //         teamId,
        //         permission,
        //         isAdmin: permission === 'admin' || permission === 'owner'
        //     }
        }


        // let response = await resolve(event, {
        //     ssr: !event.url.pathname.startsWith('/webhooks/success')
        // });
        // if (response.status === 500 && response?.body.toString().startsWith(`TypeError: Cannot read properties of undefined (reading 'length')`)) {
        //     response = {
        //         ...response,
        //         headers: {
        //             ...response.headers,
        //             // 'set-cookie': [
        //             //     "kit.session=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT",
        //             //     "teamId=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT",
        //             //     "gitlabToken=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT"
        //             // ],
        //         }
        //     }
        //     return response
        // }
        // let responseWithCookie = response

        // This check needed for switching team with HttpOnly cookie (see /src/routes/index.json.ts)
        // if (isTeamIdTokenAvailableResult && event.url.pathname !== '/index.json' && event.request.method !== 'POST' && event.url.pathname !== '/logout.json') {
        //     responseWithCookie = {
        //         ...response,
        //         headers: {
        //             ...response.headers,
        //             // 'Set-Cookie': [`teamId=${isTeamIdTokenAvailableResult};  HttpOnly; Path=/; Max-Age=15778800;`]
        //         }
        //     }
        // }
        // return responseWithCookie
        return resolve(event);
    }
);


// export const getSession: GetSession<Locals> = function (request) {
//     const payload = {
//         version,
//         uid: request.locals.session.data?.uid || null,
//         teamId: request.locals.user?.teamId || null,
//         permission: request.locals.user?.permission,
//         isAdmin: request.locals.user?.isAdmin || false
//     }
//     return payload
// };

// export async function handleError({ error, request }) {
//     sentry.captureException(error, { request });
// }