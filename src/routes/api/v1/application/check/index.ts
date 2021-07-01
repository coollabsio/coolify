import { setDefaultConfiguration } from '$lib/api/applications/configuration';
import { saveServerLog } from '$lib/api/applications/logging';
import Configuration from '$models/Configuration';
import type { Request } from '@sveltejs/kit';

export async function post(request: Request) {
	try {
		const { DOMAIN } = process.env;
		const configuration = setDefaultConfiguration(request.body);
		const sameDomainAndPath = await Configuration.find({
			'publish.path': configuration.publish.path,
			'publish.domain': configuration.publish.domain
		}).select('-_id -__v -createdAt -updatedAt');
		if (sameDomainAndPath.length > 1 || configuration.publish.domain === DOMAIN) {
			return {
				status: 200,
				body: {
					success: false,
					message: 'Domain/path are already in use.'
				}
			};
		}
		return {
			status: 200,
			body: { success: true, message: 'OK' }
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
