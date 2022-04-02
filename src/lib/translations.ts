import i18n from 'sveltekit-i18n';
import lang from './lang.json';

/** @type {import('sveltekit-i18n').Config} */
const config = {
	defaultLocale: 'en',
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

export const { t, locale, locales, loading, loadTranslations } = new i18n(config);

loading.subscribe(($loading) => $loading && console.log('Loading translations...'));
