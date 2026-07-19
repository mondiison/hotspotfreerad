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
