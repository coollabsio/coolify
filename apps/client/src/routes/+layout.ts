import { error } from '@sveltejs/kit';
import { trpc } from '$lib/store';
import type { LayoutLoad } from './$types';
import { redirect } from '@sveltejs/kit';
import Cookies from 'js-cookie';
export const ssr = false;

export const load: LayoutLoad = async ({ url }) => {
	const { pathname } = new URL(url);

	try {
		if (pathname === '/login' || pathname === '/register') {
			const baseSettings = await trpc.settings.getBaseSettings.query();
			return {
				settings: {
					...baseSettings
				}
			};
		}
		const settings = await trpc.settings.getInstanceSettings.query();
		if (settings.data.token) {
			Cookies.set('token', settings.data.token);
		}
		return {
			settings: {
				...settings
			}
		};
	} catch (err) {
		if (err?.data?.httpStatus == 401) {
			throw redirect(307, '/login');
		}
		if (err instanceof Error) {
			throw error(500, {
				message: 'An unexpected error occurred, please try again later.' + '<br><br>' + err.message
			});
		}

		throw error(500, {
			message: 'An unexpected error occurred, please try again later.'
		});
	}
};
