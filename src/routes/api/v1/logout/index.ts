import type { Request } from '@sveltejs/kit';
export async function del(request: Request) {
	request.locals.session.destroy()
	return {
		body: {
			ok: true
		}
	};
}
