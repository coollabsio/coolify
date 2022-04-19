import { toast } from '@zerodevx/svelte-toast';

export function errorNotification(message: string): void {
	console.error(message);
	if (typeof message !== 'string') {
		toast.push('Ooops, something is not okay, are you okay?');
	} else {
		toast.push(message);
	}
}
export function enhance(
	form: HTMLFormElement,
	{
		beforeSubmit,
		final,
		pending,
		error,
		result
	}: {
		beforeSubmit?: () => Promise<void>;
		final?: () => Promise<void>;
		pending?: (data: FormData, form: HTMLFormElement) => void;
		error?: (res: Response, error: Error, form: HTMLFormElement) => void;
		result?: (res: Response, form: HTMLFormElement) => void;
	}
): { destroy: () => void } {
	let current_token: unknown;

	async function handle_submit(e: Event) {
		const token = (current_token = {});
		e.preventDefault();

		let body = new FormData(form);
		const parsedData = body;

		body.forEach((data, key) => {
			if (data === '' || data === null) parsedData.delete(key);
		});
		body = parsedData;

		if (beforeSubmit) await beforeSubmit();
		if (pending) pending(body, form);

		try {
			const res = await fetch(form.action, {
				method: form.method,
				headers: {
					accept: 'application/json'
				},
				body
			});

			if (token !== current_token) return;

			if (res.ok) {
				if (result) result(res, form);
			} else if (error) {
				error(res, null, form);
			} else {
				// TODO: Add error frontend here
				const { message } = await res.json();
				errorNotification(message);
			}
		} catch (e) {
			if (error) {
				error(null, e, form);
			} else {
				throw e;
			}
		} finally {
			if (final) final();
		}
	}

	form.addEventListener('submit', handle_submit);

	return {
		destroy() {
			form.removeEventListener('submit', handle_submit);
		}
	};
}
