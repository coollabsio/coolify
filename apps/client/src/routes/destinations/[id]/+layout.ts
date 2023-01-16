import { error } from '@sveltejs/kit';
import { trpc } from '$lib/store';
import type { LayoutLoad } from './$types';
import { redirect } from '@sveltejs/kit';

function checkConfiguration(destination: any): string | null {
	let configurationPhase = null;
	if (!destination?.remoteEngine) return configurationPhase;
	if (!destination?.sshKey) {
		configurationPhase = 'sshkey';
	}
	return configurationPhase;
}

export const load: LayoutLoad = async ({ params, url }) => {
	const { pathname } = new URL(url);
	const { id } = params;
	try {
		const destination = await trpc.destinations.getDestinationById.query({ id });
		if (!destination) {
			throw redirect(307, '/destinations');
		}
		const configurationPhase = checkConfiguration(destination);
		console.log({ configurationPhase });
		// if (
		// 	configurationPhase &&
		// 	pathname !== `/applications/${params.id}/configuration/${configurationPhase}`
		// ) {
		// 	throw redirect(302, `/applications/${params.id}/configuration/${configurationPhase}`);
		// }
		return {
			destination
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
