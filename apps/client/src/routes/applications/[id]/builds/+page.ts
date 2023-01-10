import { error } from '@sveltejs/kit';
import { trpc } from '$lib/store';
import type { PageLoad } from './$types';
export const ssr = false;

export const load: PageLoad = async ({ params }) => {
	try {
		const { id } = params;
		const data = await trpc.applications.getBuilds.query({ id, skip: 0 });
		return data;
	} catch (err) {
		throw error(500, {
			message: 'An unexpected error occurred, please try again later.'
		});
	}
};
