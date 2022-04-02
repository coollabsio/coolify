import i18n from 'sveltekit-i18n';
import lang from './lang.json';

/** @type {import('sveltekit-i18n').Config} */
export const config = {
	fallbackLocale: 'en',
	translations: {
		en: { lang },
		fr: { lang }
	},
	loaders: [
		{
			locale: 'en',
			key: '',
			loader: async () => (await import('../../static/locales/en.json')).default
		},
		{
			locale: 'fr',
			key: '',
			loader: async () => (await import('../../static/locales/fr.json')).default
		}
	]
};

export const { t, loading, locales, locale, loadTranslations } = new i18n(config);
loading.subscribe(($loading) => $loading && console.log('Loading translations...'));
