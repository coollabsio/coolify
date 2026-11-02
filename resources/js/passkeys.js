import { Passkeys } from '@laravel/passkeys';

window.Passkeys = Passkeys;

window.passkeyDeviceName = () => {
    const ua = navigator.userAgent;
    const browser = /Edg\//.test(ua) ? 'Edge'
        : /Firefox\//.test(ua) ? 'Firefox'
        : /Chrome\//.test(ua) ? 'Chrome'
        : /Safari\//.test(ua) ? 'Safari'
        : 'Browser';
    const os = /iPhone|iPad/.test(ua) ? 'iOS'
        : /Android/.test(ua) ? 'Android'
        : /Mac/.test(ua) ? 'Mac'
        : /Windows/.test(ua) ? 'Windows'
        : /Linux/.test(ua) ? 'Linux'
        : 'device';

    return `${browser} on ${os}`;
};

window.dispatchEvent(new CustomEvent('passkeys:ready'));
