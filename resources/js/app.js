import './bootstrap';
import { Passkeys } from '@laravel/passkeys';
import Alpine from 'alpinejs';

window.copyText = async (text) => {
    if (! text) {
        return false;
    }

    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);

        return true;
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    const copied = document.execCommand('copy');
    textarea.remove();

    return copied;
};

window.downloadTextFile = (filename, text) => {
    if (! filename || ! text) {
        return false;
    }

    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);

    return true;
};

function passkeyMessage(error) {
    if (! error) {
        return 'Passkey action could not be completed.';
    }

    if (error.name === 'NotSupportedError') {
        return 'This browser or device does not support passkeys.';
    }

    if (error.name === 'UserCancelledError' || error.name === 'AbortError' || error.name === 'NotAllowedError') {
        return 'Passkey prompt was cancelled.';
    }

    if (error.name === 'InvalidDomainError' || String(error.message || '').toLowerCase().includes('domain')) {
        return 'Passkeys require localhost or a trusted HTTPS domain.';
    }

    return error.message || 'Passkey action could not be completed.';
}

window.passkeyLogin = function passkeyLogin() {
    return {
        supported: false,
        loading: false,
        error: '',
        async init() {
            this.supported = Passkeys.isSupported();

            if (! this.supported) {
                return;
            }

            try {
                if (await Passkeys.isAutofillSupported()) {
                    const response = await Passkeys.autofill();

                    if (response?.redirect) {
                        window.location.href = response.redirect;
                    }
                }
            } catch (error) {
                // Autofill is opportunistic; explicit passkey login remains available.
            }
        },
        async verify() {
            this.error = '';
            this.loading = true;

            try {
                const response = await Passkeys.verify();
                window.location.href = response?.redirect || '/redirect-after-login';
            } catch (error) {
                this.error = passkeyMessage(error);
            } finally {
                this.loading = false;
            }
        },
    };
};

window.passkeyManager = function passkeyManager() {
    return {
        name: '',
        loading: false,
        message: '',
        messageType: 'success',
        async register() {
            const name = this.name.trim();

            if (! name) {
                this.message = 'Enter a name for this passkey.';
                this.messageType = 'error';
                return;
            }

            this.loading = true;
            this.message = '';

            try {
                await Passkeys.register({ name });
                this.message = 'Passkey added. Refreshing list...';
                this.messageType = 'success';
                window.setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                this.message = passkeyMessage(error);
                this.messageType = 'error';
            } finally {
                this.loading = false;
            }
        },
    };
};

if (! window.Alpine) {
    window.Alpine = Alpine;
    Alpine.start();
}
