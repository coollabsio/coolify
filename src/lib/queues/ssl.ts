import { generateSSLCerts } from '$lib/letsencrypt';

export default async function (): Promise<void> {
	try {
		return await generateSSLCerts();
	} catch (error) {
		console.log(error);
		throw error;
	}
}
