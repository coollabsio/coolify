import { toast } from '@zerodevx/svelte-toast';
import { browser } from '$app/env';

export async function request(
	url,
	session,
	{
		method,
		body,
		customHeaders
	}: {
		url?: string;
		session?: any;
		fetch?: any;
		method?: string;
		body?: any;
		customHeaders?: Object;
	} = {}
) {
	let fetch;
	if (browser) {
		fetch = window.fetch;
	} else {
		fetch = session.fetch;
	}
	let headers = { 'content-type': 'application/json; charset=UTF-8' };
	if (method === 'DELETE') {
		delete headers['content-type'];
	}
	if (url.match(/api.github.com/)) {
		headers = Object.assign(headers, {
			Authorization: `token ${session.ghToken}`
		});
	}
	const config: any = {
		method: method || (body ? 'POST' : 'GET'),
		headers: {
			...headers,
			...customHeaders
		}
	};
	if (body) {
		config.body = JSON.stringify(body);
	}
	const response = await fetch(url, config);
	if (response.status >= 200 && response.status <= 299) {
		if (response.headers.get('content-type').match(/application\/json/)) {
			const json = await response.json();
			if (json?.success === false) {
				browser && toast.push(json.message);
				return Promise.reject({
					status: response.status,
					error: json.message
				});
			}
			return json;
		} else if (response.headers.get('content-type').match(/text\/plain/)) {
			return await response.text();
		} else if (response.headers.get('content-type').match(/multipart\/form-data/)) {
			return await response.formData();
		} else {
			if (response.headers.get('content-disposition')) {
				const blob = new Blob([await response.blob()], { type: 'octet/stream' });
				const link = document.createElement('a');
				link.href = URL.createObjectURL(blob);
				link.download = response.headers.get('content-disposition').split('=')[1] || 'backup.gz';
				link.target = '_blank';
				link.setAttribute('type', 'hidden');
				document.body.appendChild(link);
				link.click();
				link.remove();
				return;
			}
			return await response.blob();
		}
	} else {
		if (response.status === 401) {
			browser && toast.push('Unauthorized');
			return Promise.reject({
				status: response.status,
				error: 'Unauthorized'
			});
		} else if (response.status >= 500) {
			const error = (await response.json()).error;
			browser && toast.push(error);
			return Promise.reject({
				status: response.status,
				error: error || 'Oops, something is not okay. Are you okay?'
			});
		} else {
			browser && toast.push(response.statusText);
			return Promise.reject({
				status: response.status,
				error: response.statusText
			});
		}
	}
}
