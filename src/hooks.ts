import dotEnvExtended from 'dotenv-extended';
dotEnvExtended.load();
import type { GetSession } from '@sveltejs/kit';
import { handleSession } from 'svelte-kit-cookie-session';
import { getUserDetails, sentry } from '$lib/common';
import { version } from '$lib/common';
import cookie from 'cookie';
import { dev } from '$app/env';
import { locales } from '$lib/translations';

const whiteLabeled = process.env['COOLIFY_WHITE_LABELED'] === 'true';
const whiteLabelDetails = {
	icon: (whiteLabeled && process.env['COOLIFY_WHITE_LABELED_ICON']) || null
};

export const handle = handleSession(
	{
		secret: process.env['COOLIFY_SECRET_KEY'],
		expires: 30,
		cookie: { secure: false }
	},
	async function ({ event, resolve }) {
		let response;

		const { url, request } = event;

		// Get defined locales
		const supportedLocales = locales.get();
		let locale;

		if (event.locals.cookies['lang']) {
			locale = event.locals.cookies['lang'];
		} else if (!locale) {
			locale = `${`${request.headers.get('accept-language')}`.match(
				/[a-zA-Z]+?(?=-|_|,|;)/
			)}`.toLowerCase();
		}

		// Set default locale if user preferred locale does not match
		if (!supportedLocales.includes(locale)) locale = 'en';

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
				ssr: !event.url.pathname.startsWith('/webhooks/success'),
				transformPage: ({ html }) => html.replace(/<html.*>/, `<html lang="${locale}">`)
			});
		} catch (error) {
			console.log(error);
			response = await resolve(event, {
				ssr: !event.url.pathname.startsWith('/webhooks/success'),
				transformPage: ({ html }) => html.replace(/<html.*>/, `<html lang="${locale}">`)
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

		response.headers.append(
			'Set-Cookie',
			cookie.serialize('lang', locale, {
				path: '/',
				sameSite: 'strict',
				maxAge: 30 * 24 * 60 * 60
			})
		);

		return response;
	}
);

export const getSession: GetSession = function ({ locals }) {
	return {
		lang: locals.cookies.lang,
		version,
		whiteLabeled,
		whiteLabelDetails,
		...locals.session.data
	};
};

export async function handleError({ error, event }) {
	if (!dev) sentry.captureException(error, event);
}
