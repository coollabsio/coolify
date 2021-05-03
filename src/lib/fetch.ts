export async function request(
	url,
	{
		session,
		fetch,
		method,
		body,
		...customConfig
	}: { session: any; fetch: any; method?: string; body?: any; [key: string]: Object }
) {
	let headers = { 'Content-type': 'application/json; charset=UTF-8' };
	if (method === 'DELETE') {
		delete headers['Content-type'];
	}
	const isGithub = url.match(/api.github.com/);
	headers = Object.assign(headers, {
		Authorization: isGithub && `token ${session.ghToken}`
	});

	const config: any = {
		cache: 'no-cache',
		method: method || (body ? 'POST' : 'GET'),
		...customConfig,
		headers: {
			...headers,
			...customConfig.headers
		}
	};
	const response = await fetch(url, config);
	// console.log(response)
	if (response.status >= 200 && response.status <= 299) {
		if (response.headers.get('content-type').match(/application\/json/)) {
			return await response.json();
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
			return Promise.reject({
				code: response.status,
				error: 'Unauthorized'
			});
		} else if (response.status >= 500) {
			const error = (await response.json()).message;
			return Promise.reject({
				code: response.status,
				error: error || 'Oops, something is not okay. Are you okay?'
			});
		} else {
			return Promise.reject({
				code: response.status,
				error: response.statusText
			});
		}
	}
}
