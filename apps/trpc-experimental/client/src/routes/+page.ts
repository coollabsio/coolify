import { error } from '@sveltejs/kit';
import { trpc } from '$lib/store';
export const ssr = false;

export const load = async () => {
	try {
		return await trpc.dashboard.resources.query();
	} catch (err) {
		throw error(500, {
			message: 'An unexpected error occurred, please try again later.'
		});
	}
};
