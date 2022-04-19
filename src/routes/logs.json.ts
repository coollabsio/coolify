import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler = async (event) => {
	const data = await event.request.json();
	console.log(data);
	return {
		status: 200,
		body: {}
	};
};
