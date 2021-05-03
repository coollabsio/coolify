import type { Request } from '@sveltejs/kit';

export async function get(request: Request) {
	return {
		status: 500,
		body: { message: 'Something made an error!' }
	};
}
