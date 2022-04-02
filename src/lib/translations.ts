import i18n from 'sveltekit-i18n';
import lang from './lang.json';

/** @type {import('sveltekit-i18n').Config} */
export const config = {
	fallbackLocale: 'en-US',
	translations: {
		'en-US': { lang },
		'fr-FR': { lang }
	},
	loaders: [
		{
			locale: 'en-US',
			key: '',
			loader: async () => (await import('../../static/locales/en.json')).default
		},
		{
			locale: 'fr-FR',
			key: '',
			loader: async () => (await import('../../static/locales/fr.json')).default
		}
	]
};

export const { t, loading, locales, locale, loadTranslations } = new i18n(config);
loading.subscribe(($loading) => $loading && console.log('Loading translations...'));
