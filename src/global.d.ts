/// <reference types="@sveltejs/kit" />
/// <reference types="svelte" />
/// <reference types="vite/client" />

export type DateTimeFormatOptions = {
	localeMatcher?: 'lookup' | 'best fit';
	weekday?: 'long' | 'short' | 'narrow';
	era?: 'long' | 'short' | 'narrow';
	year?: 'numeric' | '2-digit';
	month?: 'numeric' | '2-digit' | 'long' | 'short' | 'narrow';
	day?: 'numeric' | '2-digit';
	hour?: 'numeric' | '2-digit';
	minute?: 'numeric' | '2-digit';
	second?: 'numeric' | '2-digit';
	timeZoneName?: 'long' | 'short';
	formatMatcher?: 'basic' | 'best fit';
	hour12?: boolean;
	timeZone?: string;
}
export type Application = {
	github: {
		installation: {
			id: Number;
		},
		app: {
			id: Number;
		},
	},
	repository: {
		id: Number;
		organization: String;
		name: String;
		branch: String;
	},
	general: {
		deployId: String;
		nickname: String;
		workdir: String;
	},
	build: {
		pack: String;
		directory: String;
		command: {
			build: String | null;
			installation: String;
		}
		container: {
			name: String;
			tag: String;
			baseSHA: String;
		}
	},
	publish: {
		directory: String;
		domain: String;
		path: String;
		port: Number;
		secrets: Array<Object>
	},
}
export type Dashboard = {
	applications?: {
		deployed: [{
			configuration: Application
			UpdatedAt: any
		}]

	}

}