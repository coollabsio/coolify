import { renewSSLCerts } from '$lib/letsencrypt';

export default async function (): Promise<void> {
	try {
		return await renewSSLCerts();
	} catch (error) {
		console.log(error);
		throw error;
	}
}
