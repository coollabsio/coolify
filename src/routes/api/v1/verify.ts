// import { deleteCookies } from '$lib/api/common';
// import { verifyUserId } from '$lib/api/common';
// import type { Request } from '@sveltejs/kit';
// import * as cookie from 'cookie';

// export async function post(request: Request) {
// 	const { coolToken } = cookie.parse(request.headers.cookie || '');
// 	try {
// 		await verifyUserId(coolToken);
// 		return {
// 			status: 200,
// 			body: { success: true }
// 		};
// 	} catch (error) {
// 		return {
// 			status: 301,
// 			headers: {
// 				location: '/',
// 				'set-cookie': [...deleteCookies]
// 			},
// 			body: { error: 'Unauthorized' }
// 		};
// 	}
// }
