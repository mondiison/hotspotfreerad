import './bootstrap';
import { Passkeys } from '@laravel/passkeys';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

Alpine.plugin(collapse);

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

/**
 * 2026-10-02: backs the router/customer-facing "Download QR" buttons --
 * the QR itself is only ever rendered server-side as an SVG string
 * (BaconQrCode), which isn't a file format most printers/photo apps
 * handle as gracefully as a plain PNG. Rasterizes it client-side via a
 * throwaway <canvas> rather than adding a server-side PNG rendering
 * dependency just for this.
 */
window.downloadSvgAsPng = (svgMarkup, filename, scale = 6) => {
    if (! svgMarkup || ! filename) {
        return Promise.resolve(false);
    }

    return new Promise((resolve) => {
        const svgBlob = new Blob([svgMarkup], { type: 'image/svg+xml' });
        const svgUrl = URL.createObjectURL(svgBlob);
        const image = new Image();

        image.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = (image.width || 200) * scale;
            canvas.height = (image.height || 200) * scale;

            const context = canvas.getContext('2d');
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.drawImage(image, 0, 0, canvas.width, canvas.height);

            URL.revokeObjectURL(svgUrl);

            canvas.toBlob((pngBlob) => {
                if (! pngBlob) {
                    resolve(false);

                    return;
                }

                const link = document.createElement('a');
                link.href = URL.createObjectURL(pngBlob);
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(link.href);
                resolve(true);
            }, 'image/png');
        };

        image.onerror = () => {
            URL.revokeObjectURL(svgUrl);
            resolve(false);
        };

        image.src = svgUrl;
    });
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
