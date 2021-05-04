import type { Request } from '@sveltejs/kit';

export async function get(request: Request) {
	return {
		status: 500,
		body: { error: 'Something made an error!' }
	};
}
