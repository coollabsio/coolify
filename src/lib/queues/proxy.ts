import { ErrorHandler } from '$lib/database';
import { configureHAProxy } from '$lib/haproxy/configuration';

export default async function () {
	try {
		return await configureHAProxy();
	} catch (error) {
		ErrorHandler(error.response.body || error);
	}
}
