import { error } from '@sveltejs/kit';
import { trpc } from '$lib/store';
import type { LayoutLoad } from './$types';
import { redirect } from '@sveltejs/kit';

function checkConfiguration(database: any): string | null {
	let configurationPhase = null;
	if (!database.type) {
		configurationPhase = 'type';
	} else if (!database.version) {
		configurationPhase = 'version';
	} else if (!database.destinationDockerId) {
		configurationPhase = 'destination';
	}
	return configurationPhase;
}

export const load: LayoutLoad = async ({ params, url }) => {
	const { pathname } = new URL(url);
	const { id } = params;
	try {
		const database = await trpc.databases.getDatabaseById.query({ id });
		if (!database) {
			throw redirect(307, '/databases');
		}
		const configurationPhase = checkConfiguration(database);
		console.log({ configurationPhase });
		// if (
		// 	configurationPhase &&
		// 	pathname !== `/applications/${params.id}/configuration/${configurationPhase}`
		// ) {
		// 	throw redirect(302, `/applications/${params.id}/configuration/${configurationPhase}`);
		// }
		return {
			database
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
