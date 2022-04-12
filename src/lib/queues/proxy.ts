import { ErrorHandler } from '$lib/database';
import { configureHAProxy } from '$lib/haproxy/configuration';

export default async function (): Promise<void | {
	status: number;
	body: { message: string; error: string };
}> {
	try {
		return await configureHAProxy();
	} catch (error) {
		return ErrorHandler(error.response?.body || error);
	}
}
