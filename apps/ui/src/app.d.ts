/// <reference types="@sveltejs/kit" />

// See https://kit.svelte.dev/docs/types#app
// for information about these interfaces
declare namespace App {
	// interface Locals {}
	// interface Platform {}
	// interface Session {}
	interface Stuff {
		service: any;
		application: any;
		isRunning: boolean;
		appId: string;
		readOnly: boolean;
		source: string;
		settings: string;
		database: Record<string, any>;
		versions: string;
		privatePort: string;
	}
}

declare const GITPOD_WORKSPACE_URL: string

  