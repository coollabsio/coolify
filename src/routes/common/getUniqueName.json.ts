import { uniqueName } from '$lib/common';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async () => {
	return {
		body: {
			name: uniqueName()
		}
	};
};
