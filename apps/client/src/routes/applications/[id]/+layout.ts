import { error } from '@sveltejs/kit';
import { trpc } from '$lib/store';
import type { LayoutLoad } from './$types';
import { redirect } from '@sveltejs/kit';

function checkConfiguration(application: any): string | null {
	let configurationPhase = null;
	if (!application.gitSourceId && !application.simpleDockerfile) {
		return (configurationPhase = 'source');
	}
	if (application.simpleDockerfile) {
		if (!application.destinationDockerId) {
			configurationPhase = 'destination';
		}
		return configurationPhase;
	} else if (!application.repository && !application.branch) {
		configurationPhase = 'repository';
	} else if (!application.destinationDockerId) {
		configurationPhase = 'destination';
	} else if (!application.buildPack) {
		configurationPhase = 'buildpack';
	}
	return configurationPhase;
}

export const load: LayoutLoad = async ({ params, url }) => {
	const { pathname } = new URL(url);
	const { id } = params;
	try {
		const application = await trpc.applications.getApplicationById.query({ id });
		if (!application) {
			throw redirect(307, '/applications');
		}
		const configurationPhase = checkConfiguration(application);
		console.log({ configurationPhase });
		// if (
		// 	configurationPhase &&
		// 	pathname !== `/applications/${params.id}/configuration/${configurationPhase}`
		// ) {
		// 	throw redirect(302, `/applications/${params.id}/configuration/${configurationPhase}`);
		// }
		return {
			application
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
