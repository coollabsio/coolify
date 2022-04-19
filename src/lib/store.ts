import { writable, type Writable } from 'svelte/store';

export const gitTokens: Writable<{ githubToken: string | null; gitlabToken: string | null }> =
	writable({
		githubToken: null,
		gitlabToken: null
	});
