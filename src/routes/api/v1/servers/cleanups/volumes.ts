import { saveServerLog } from '$lib/api/applications/logging';
import { execShellAsync } from '$lib/api/common';
import type { Request } from '@sveltejs/kit';

export async function post(request: Request) {
	try {
		const output = await execShellAsync('docker volume prune -f');
		return {
			status: 200,
			body: {
				message: 'OK',
				output: output
					.replace(/^(?=\n)$|^\s*|\s*$|\n\n+/gm, '')
					.split('\n')
					.pop()
			}
		};
	} catch (error) {
		await saveServerLog(error);
		return {
			status: 500,
			body: {
				error: error.message || error
			}
		};
	}
}
