/// <reference types="@sveltejs/kit" />

declare namespace App {
	interface Locals {
		session: import('svelte-kit-cookie-session').Session<SessionData>;
		cookies: Record<string, string>;
	}
	interface Platform {}
	interface Session extends SessionData {}
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

interface SessionData {
	whiteLabeled: boolean;
	version?: string;
	userId?: string | null;
	teamId?: string | null;
	permission?: string;
	isAdmin?: boolean;
	expires?: string | null;
}

type DateTimeFormatOptions = {
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
};

interface Hash {
	iv: string;
	content: string;
}

type RawHaproxyConfiguration = {
	_version: number;
	data: string;
};

type NewTransaction = {
	_version: number;
	id: string;
	status: string;
};

type Application = {
	name: string;
	domain: string;
};
