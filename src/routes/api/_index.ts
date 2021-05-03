import type { Request } from '@sveltejs/kit';

// export async function api(request: Request, resource: string, data?: {}) {
//     const base = 'https://github.com/';
//     if (!request.context.isLoggedIn) {
//         return { status: 401, body: 'Unauthorized' };
//     }

//     const res = await fetch(`${base}${resource}`, {
//         method: request.method,
//         headers: {
//             'content-type': 'application/json'
//         },
//         body: data && JSON.stringify(data)
//     });
//     return {
//         status: res.status,
//         body: await res.json()
//     };
// }

export async function githubAPI(request: Request, resource: string, token?: string, data?: {}) {
	const base = 'https://api.github.com';
	const res = await fetch(`${base}${resource}`, {
		method: request.method,
		headers: {
			'content-type': 'application/json',
			accept: 'application/json',
			authorization: token ? `token ${token}` : ''
		},
		body: data && JSON.stringify(data)
	});
	return {
		status: res.status,
		body: await res.json()
	};
}
