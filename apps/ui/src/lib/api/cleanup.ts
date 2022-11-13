export async function cleanupApplications() {
		try {
			const sure = confirm(
				'Are you sure? This will delete all UNCONFIGURED applications and their data.'
			);
			if (sure) {
				await post(`/applications/cleanup/unconfigured`, {});
				return window.location.reload();
			}
		} catch (error) {
			return errorNotification(error);
		}
	}
export async function cleanupServices() {
		try {
			const sure = confirm(
				'Are you sure? This will delete all UNCONFIGURED services and their data.'
			);
			if (sure) {
				await post(`/services/cleanup/unconfigured`, {});
				return window.location.reload();
			}
		} catch (error) {
			return errorNotification(error);
		}
	}
export async function cleanupDatabases() {
		try {
			const sure = confirm(
				'Are you sure? This will delete all UNCONFIGURED databases and their data.'
			);
			if (sure) {
				await post(`/databases/cleanup/unconfigured`, {});
				return window.location.reload();
			}
		} catch (error) {
			return errorNotification(error);
		}
	}