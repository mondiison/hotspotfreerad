import './bootstrap';
import QRCode from 'qrcode';

window.renderQrCode = async (element, text) => {
    if (! element || ! text) {
        return;
    }

    try {
        element.innerHTML = await QRCode.toString(text, {
            type: 'svg',
            errorCorrectionLevel: 'M',
            margin: 1,
            width: 184,
            color: {
                dark: '#09090b',
                light: '#ffffff',
            },
        });
    } catch (error) {
        element.innerHTML = '<p class="text-sm text-red-700">Unable to render QR code. Use the setup key instead.</p>';
    }
};

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
