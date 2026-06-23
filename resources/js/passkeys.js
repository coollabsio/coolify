import { Passkeys } from '@laravel/passkeys';

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

Passkeys.configure({
    fetch: {
        credentials: 'include',
        headers: {
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json',
        },
    },
});

function redirectAfterAuth(response) {
    const destination = response?.redirect ?? '/';

    window.location.href = destination;
}

function getPasskeySupportError() {
    if (!window.isSecureContext) {
        return 'Passkeys require a secure connection (HTTPS).';
    }

    if (typeof window.PublicKeyCredential === 'undefined') {
        return 'Your browser does not support passkeys.';
    }

    if (!Passkeys.isSupported()) {
        return 'Passkeys are not supported in this browser.';
    }

    return null;
}

window.coolifyPasskeys = {
    getSupportError() {
        return getPasskeySupportError();
    },

    isSupported() {
        return getPasskeySupportError() === null;
    },

    async login() {
        const response = await Passkeys.verify({
            routes: {
                options: '/passkeys/login/options',
                submit: '/passkeys/login',
            },
        });

        redirectAfterAuth(response);

        return response;
    },

    async register(name) {
        try {
            const response = await Passkeys.register({
                name,
                routes: {
                    options: '/user/passkeys/options',
                    submit: '/user/passkeys',
                },
            });

            window.location.reload();

            return response;
        } catch (error) {
            if (error?.message === 'Password confirmation required.') {
                window.location.href = '/profile/add-passkey';

                return;
            }

            throw error;
        }
    },

    async confirm() {
        const response = await Passkeys.verify({
            routes: {
                options: '/passkeys/confirm/options',
                submit: '/passkeys/confirm',
            },
        });

        redirectAfterAuth(response);

        return response;
    },

    initLoginAutofill() {
        if (! Passkeys.isAutofillSupported?.()) {
            return;
        }

        Passkeys.autofill({
            routes: {
                options: '/passkeys/login/options',
                submit: '/passkeys/login',
            },
            onSuccess: redirectAfterAuth,
        });
    },
};

document.addEventListener('DOMContentLoaded', () => {
    if (document.querySelector('[data-passkey-autofill]')) {
        window.coolifyPasskeys.initLoginAutofill();
    }
});
