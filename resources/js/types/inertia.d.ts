import "@inertiajs/core";

declare module "@inertiajs/core" {
	export interface InertiaConfig {
		sharedPageProps: {
			appName: string;
		};
		// flashDataType: {
		//     toast?: { type: "success" | "error"; message: string };
		// };
		errorValueType: string[];
		layoutProps: {
			title: string;
		};
	}
}
