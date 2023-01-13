import { error } from '@sveltejs/kit';
import { trpc } from '$lib/store';
import type { LayoutLoad } from './$types';
import { redirect } from '@sveltejs/kit';

export const load: LayoutLoad = async ({ params, url }) => {
	const { pathname } = new URL(url);
	const { id } = params;
	try {
		const source = await trpc.sources.getSourceById.query({ id });
		if (!source) {
			throw redirect(307, '/sources');
		}

		// if (
		// 	configurationPhase &&
		// 	pathname !== `/applications/${params.id}/configuration/${configurationPhase}`
		// ) {
		// 	throw redirect(302, `/applications/${params.id}/configuration/${configurationPhase}`);
		// }
		return {
			source
		};
	} catch (err) {
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
