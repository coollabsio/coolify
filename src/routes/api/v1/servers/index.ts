import { saveServerLog } from '$lib/api/applications/logging';
import { execShellAsync } from '$lib/api/common';
import { docker } from '$lib/api/docker';
import type { Request } from '@sveltejs/kit';
import systeminformation from 'systeminformation';

export async function get(request: Request) {
	try {
		const df = await execShellAsync(`docker system df  --format '{{ json . }}'`);
		const dockerReclaimable = df
			.split('\n')
			.filter((n) => n)
			.map((s) => JSON.parse(s));

		return {
			status: 200,
			body: {
				hostname: await (await systeminformation.osInfo()).hostname,
				filesystems: await (
					await systeminformation.fsSize()
				).filter((fs) => !fs.fs.match('/dev/loop') || !fs.fs.match('/var/lib/docker/')),
				dockerReclaimable
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
