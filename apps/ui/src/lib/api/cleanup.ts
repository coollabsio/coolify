import { errorNotification } from "$lib/common";
import { post } from '$lib/api'
// Receives what as: databases, services, applications
export async function cleanup(what:any) {
	try {
		const sure = confirm(
			`Are you sure? This will delete all UNCONFIGURED ${what} and their data.`
		);
		if (sure) {
			await post(`/${what}/cleanup/unconfigured`, {});
			return window.location.reload();
		}
	} catch (error) {
		return errorNotification(error);
	}
}