import { saveServerLog } from '$lib/api/applications/logging';
import Settings from '$models/Settings';
import type { Request } from '@sveltejs/kit';
const applicationName = 'coolify';

export async function get(request: Request) {
	try {
		let settings = await Settings.findOne({ applicationName }).select('-_id -__v');
		const payload = {
			applicationName,
			allowRegistration: false,
			...settings._doc
		};
		return {
			status: 200,
			body: {
				...payload
			}
		};
	} catch (error) {
		await saveServerLog(error);
		return {
			status: 500,
			body: {
				error
			}
		};
	}
}
export async function post(request: Request) {
	try {
		const settings = await Settings.findOneAndUpdate(
			{ applicationName },
			{ applicationName, ...request.body },
			{ upsert: true, new: true }
		).select('-_id -__v');
		return {
			status: 201,
			body: {
				...settings._doc
			}
		};
	} catch (error) {
		await saveServerLog(error);
		return {
			status: 500,
			body: {
				error
			}
		};
	}
}
