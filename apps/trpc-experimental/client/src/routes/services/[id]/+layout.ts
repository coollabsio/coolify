import { error } from '@sveltejs/kit';
import { trpc } from '$lib/store';
import type { LayoutLoad } from './$types';
import { redirect } from '@sveltejs/kit';

function checkConfiguration(service: any): string | null {
	let configurationPhase = null;
	if (!service.type) {
		configurationPhase = 'type';
	} else if (!service.destinationDockerId) {
		configurationPhase = 'destination';
	}
	return configurationPhase;
}

export const load: LayoutLoad = async ({ params, url }) => {
	const { pathname } = new URL(url);
	const { id } = params;
	try {
		const service = await trpc.services.getServices.query({ id });
		if (!service) {
			throw redirect(307, '/services');
		}
		const configurationPhase = checkConfiguration(service);
		console.log({ configurationPhase });
		// if (
		// 	configurationPhase &&
		// 	pathname !== `/applications/${params.id}/configuration/${configurationPhase}`
		// ) {
		// 	throw redirect(302, `/applications/${params.id}/configuration/${configurationPhase}`);
		// }
		return {
			...service.data
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
