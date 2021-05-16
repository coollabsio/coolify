import type { Request } from '@sveltejs/kit';
import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc.js';
import relativeTime from 'dayjs/plugin/relativeTime.js';
import Deployment from '$models/Deployment';
dayjs.extend(utc);
dayjs.extend(relativeTime);
export async function get(request: Request) {
	try {
		const repoId = request.query.get('repoId');
		const branch = request.query.get('branch');
		const page = request.query.get('page');
		const onePage = 5;
		const show = Number(page) * onePage || 5;
		const deploy: any = await Deployment.find({ repoId, branch })
			.select('-_id -__v -repoId')
			.sort({ createdAt: 'desc' })
			.limit(show);

		const finalLogs = deploy.map((d) => {
			const finalLogs = { ...d._doc };
			const updatedAt = dayjs(d.updatedAt).utc();
			finalLogs.took = updatedAt.diff(dayjs(d.createdAt)) / 1000;
			finalLogs.since = updatedAt.fromNow();
			return finalLogs;
		});
		return {
			status: 200,
			body: {
				success: true,
				logs: finalLogs
			}
		};
	} catch (error) {
		return {
			status: 500,
			body: {
				error: error.message || error
			}
		};
	}
}
