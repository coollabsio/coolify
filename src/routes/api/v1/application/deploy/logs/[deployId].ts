import type { Request } from '@sveltejs/kit';
import ApplicationLog from '$models/ApplicationLog';
import Deployment from '$models/Deployment';
import dayjs from 'dayjs';

export async function get(request: Request) {
	const { deployId } = request.params;
	try {
		const logs: any = await ApplicationLog.find({ deployId })
			.select('-_id -__v')
			.sort({ createdAt: 'asc' });

		const deploy: any = await Deployment.findOne({ deployId })
			.select('-_id -__v')
			.sort({ createdAt: 'desc' });

		const finalLogs: any = {};
		finalLogs.progress = deploy.progress;
		finalLogs.events = logs.map((log) => log.event);
		finalLogs.human = dayjs(deploy.updatedAt).from(dayjs(deploy.updatedAt));
		return {
			status: 200,
			body: {
				...finalLogs
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
