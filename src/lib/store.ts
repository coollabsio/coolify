import { writable } from 'svelte/store';

export const gitTokens = writable({
	githubToken: null,
	gitlabToken: null
});
