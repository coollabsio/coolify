import { writable, type Writable, type Readable, readable } from 'svelte/store';

export const gitTokens: Writable<{ githubToken: string | null; gitlabToken: string | null }> =
	writable({
		githubToken: null,
		gitlabToken: null
	});
export const disabledButton: Writable<boolean> = writable(false);

export const features: Readable<{ latestVersion: string; beta: boolean }> = readable({
	beta: window.localStorage.getItem('beta') === 'true',
	latestVersion: window.localStorage.getItem('latestVersion')
});
