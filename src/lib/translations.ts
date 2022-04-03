import i18n from 'sveltekit-i18n';
import lang from './lang.json';
import * as fs from 'fs';

// Get all translations files
const loaders = [];
const translations = {};
fs.readdir('src/lib/locales/', (err, files) => {
	files.forEach((file) => {
		if (file.endsWith('.json')) {
			const lang_iso = file.replace('.json', '');

			loaders.push({
				locale: file.replace('.json', ''),
				key: '',
				/* @vite-ignore */
				loader: async () => (await import(`./locales/${lang_iso}.json`)).default
			});

			translations[lang_iso] = { lang };
		}
	});
});

/** @type {import('sveltekit-i18n').Config} */
const config = {
	fallbackLocale: 'en',
	translations: translations,
	loaders: loaders
};

export const { t, loading, locales, locale, loadTranslations } = new i18n(config);

loading.subscribe(($loading) => $loading);
