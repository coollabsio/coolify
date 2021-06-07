import { setDefaultConfiguration } from '$lib/api/applications/configuration';
import { saveServerLog } from '$lib/api/applications/logging';
import { docker } from '$lib/api/docker';
import Configuration from '$models/Configuration';
import type { Request } from '@sveltejs/kit';

export async function post(request: Request) {
	try {
		const { DOMAIN } = process.env;
		const configuration = setDefaultConfiguration(request.body);
		const configurationFound = await Configuration.find({
			'repository.id': { '$ne': configuration.repository.id },
			'publish.domain': configuration.publish.domain
		})
		if (configurationFound.length > 0 || configuration.publish.domain === DOMAIN) {
			return {
				status: 200,
				body: {
					success: false,
					message: 'Domain already in use.'
				}
			};
		}
		return {
			status: 200,
			body: { success: true, message: 'OK' }
		}
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
