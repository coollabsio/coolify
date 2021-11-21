import dotEnvExtended from 'dotenv-extended';
dotEnvExtended.load();
import type { GetSession } from "@sveltejs/kit";
import { handleSession } from "svelte-kit-cookie-session";

export const handle = handleSession(
    {
        secret: process.env['SECRET_KEY'],
        expires: 30
    },
    async function ({ request, resolve }) {
        const response = await resolve(request);

        if (!response.body || !response.headers) {
            return response;
        }
        return response;
    }
);


export const getSession: GetSession<Locals> = function (request) {
    return request.locals.session.data;
};