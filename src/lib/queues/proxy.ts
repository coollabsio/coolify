import { dev } from '$app/env';
import { ErrorHandler } from '$lib/database';
import { configureHAProxy } from '$lib/haproxy/configuration';

export default async function () {
	try {
		return await configureHAProxy();
	} catch (error) {
		console.log(error.response?.body || error);
		return ErrorHandler(error.response?.body || error);
	}
}
