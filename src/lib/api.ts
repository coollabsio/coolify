async function send({
	method,
	path,
	data = {},
	headers,
	timeout = 120000
}): Promise<Record<string, unknown>> {
	const controller = new AbortController();
	const id = setTimeout(() => controller.abort(), timeout);
	const opts = { method, headers: {}, body: null, signal: controller.signal };
	if (Object.keys(data).length > 0) {
		const parsedData = data;
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
	if (contentType) {
		if (contentType?.indexOf('application/json') !== -1) {
			responseData = await response.json();
		} else if (contentType?.indexOf('text/plain') !== -1) {
			responseData = await response.text();
		} else {
			return {};
		}
	} else {
		return {};
	}
	if (!response.ok) throw responseData;
	return responseData;
}

export function get(
	path: string,
	headers?: Record<string, unknown>
): Promise<Record<string, unknown>> {
	return send({ method: 'GET', path, headers });
}

export function del(
	path: string,
	data: Record<string, unknown>,
	headers?: Record<string, unknown>
): Promise<Record<string, unknown>> {
	return send({ method: 'DELETE', path, data, headers });
}

export function post(
	path: string,
	data: Record<string, unknown>,
	headers?: Record<string, unknown>
): Promise<Record<string, unknown>> {
	return send({ method: 'POST', path, data, headers });
}

export function put(
	path: string,
	data: Record<string, unknown>,
	headers?: Record<string, unknown>
): Promise<Record<string, unknown>> {
	return send({ method: 'PUT', path, data, headers });
}
