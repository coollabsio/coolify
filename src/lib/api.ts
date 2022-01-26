async function send({ method, path, data = {}, headers, timeout = 30000 }) {
	const controller = new AbortController();
	const id = setTimeout(() => controller.abort(), timeout);
	const opts = { method, headers: {}, body: null, signal: controller.signal };
	if (Object.keys(data).length > 0) {
		let parsedData = data;
		for (const [key, value] of Object.entries(data)) {
			if (value === '') {
				parsedData[key] = null;
			}
		}
		if (parsedData) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(parsedData);
		}
	}

	if (headers) {
		opts.headers = {
			...opts.headers,
			...headers
		};
	}
	const response = await fetch(`${path}`, opts);

	clearTimeout(id);

	const contentType = response.headers.get('content-type');

	let responseData = {};
	if (contentType?.indexOf('application/json') !== -1) {
		responseData = await response.json();
	} else {
		responseData = await response.text();
	}
	if (!response.ok) throw responseData;
	return responseData;
}

export function get(path, headers = {}) {
	return send({ method: 'GET', path, headers });
}

export function del(path, data, headers = {}) {
	return send({ method: 'DELETE', path, data, headers });
}

export function post(path, data, headers = {}) {
	return send({ method: 'POST', path, data, headers });
}

export function put(path, data, headers = {}) {
	return send({ method: 'PUT', path, data, headers });
}
