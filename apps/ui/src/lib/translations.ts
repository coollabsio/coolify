import i18n from 'sveltekit-i18n';
import { derived, writable } from 'svelte/store';
import lang from './lang.json';

export const currentLocale = writable('en');
export const debugTranslation = writable(false);

/** @type {import('sveltekit-i18n').Config} */
export const config = {
	fallbackLocale: 'en',
	translations: {
		en: { lang },
		es: { lang },
		pt: { lang },
		ko: { lang },
		fr: { lang },
		cs: { lang }
	},
	loaders: [
		{
			locale: 'en',
			key: '',
			loader: async () => (await import('./locales/en.json')).default
		},
		{
			locale: 'es',
			key: '',
			loader: async () => (await import('./locales/es.json')).default
		},
		{
			locale: 'pt',
			key: '',
			loader: async () => (await import('./locales/pt.json')).default
		},
		{
			locale: 'fr',
			key: '',
			loader: async () => (await import('./locales/fr.json')).default
		},
		{
			locale: 'ko',
			key: '',
			loader: async () => (await import('./locales/ko.json')).default
		},
		{
			locale: 'cs',
			key: '',
			loader: async () => (await import('./locales/cs.json')).default
		}
	]
};

export const { t, locales, locale, loadTranslations } = new i18n(config);
