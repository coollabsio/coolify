import dotEnvExtended from 'dotenv-extended';
dotEnvExtended.load();
import type { GetSession } from '@sveltejs/kit';
import { handleSession } from 'svelte-kit-cookie-session';
import { getUserDetails, sentry } from '$lib/common';
import { version } from '$lib/common';
import cookie from 'cookie';
import { dev } from '$app/env';
import { locales } from '$lib/translations';

const routeRegex = new RegExp(/^\/[^.]*([?#].*)?$/);

export const handle = handleSession(
	{
		secret: process.env['COOLIFY_SECRET_KEY'],
		expires: 30,
		cookie: { secure: false }
	},
	async function ({ event, resolve }) {
		let response;
		let locale;

		const { url, request } = event;
		const { pathname } = url;

		// If this request is a route request
		if (routeRegex.test(pathname)) {
			// Get defined locales
			const supportedLocales = locales.get();

			// Try to get locale from `pathname`.
			locale = supportedLocales.find(
				(l) => `${l}`.toLowerCase() === `${pathname.match(/[^/]+?(?=\/|$)/)}`.toLowerCase()
			);

			if (!locale) {
				// Get user preferred locale
				locale = `${`${request.headers.get('accept-language')}`.match(/[a-zA-Z-]+?(?=_|,|;)/)}`;

				// Set default locale if user preferred locale does not match
				if (!supportedLocales.includes(locale)) locale = 'en-US';

				// 302 redirect
				return new Response(undefined, {
					headers: { location: `/${locale}${pathname}` },
					status: 302
				});
			}
		}

		try {
			if (event.locals.cookies) {
				if (event.locals.cookies['kit.session']) {
					const { permission, teamId, userId } = await getUserDetails(event, false);
					const newSession = {
						userId,
						teamId,
						permission,
						isAdmin: permission === 'admin' || permission === 'owner',
						expires: event.locals.session.data.expires
					};

					if (JSON.stringify(event.locals.session.data) !== JSON.stringify(newSession)) {
						event.locals.session.data = { ...newSession };
					}
				}
			}

			response = await resolve(event, {
				ssr: !event.url.pathname.startsWith('/webhooks/success')
			});
		} catch (error) {
			response = await resolve(event, {
				ssr: !event.url.pathname.startsWith('/webhooks/success')
			});
			response.headers.append(
				'Set-Cookie',
				cookie.serialize('kit.session', '', {
					path: '/',
					expires: new Date('Thu, 01 Jan 1970 00:00:01 GMT')
				})
			);
			response.headers.append(
				'Set-Cookie',
				cookie.serialize('teamId', '', {
					path: '/',
					expires: new Date('Thu, 01 Jan 1970 00:00:01 GMT')
				})
			);
			response.headers.append(
				'Set-Cookie',
				cookie.serialize('gitlabToken', '', {
					path: '/',
					expires: new Date('Thu, 01 Jan 1970 00:00:01 GMT')
				})
			);
		}

		if (locale && response.headers.get('content-type') === 'text/html') {
			const body = await response.text();
			return new Response(body.replace(/<html.*>/, `<html lang="${locale}">`), response);
		}

		return response;
	}
);

export const getSession: GetSession = function ({ locals }) {
	return {
		version,
		...locals.session.data
	};
};

export async function handleError({ error, event }) {
	if (!dev) sentry.captureException(error, event);
}
