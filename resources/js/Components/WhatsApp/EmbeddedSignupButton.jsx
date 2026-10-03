import PrimaryButton from '@/Components/PrimaryButton';
import { toast } from '@/Components/ToastProvider';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const FB_ORIGIN = /^https:\/\/([a-z0-9-]+\.)?facebook\.com$/;

let sdkPromise = null;

function loadFacebookSdk(appId, version) {
    if (window.FB) {
        return Promise.resolve(window.FB);
    }
    if (sdkPromise) {
        return sdkPromise;
    }

    sdkPromise = new Promise((resolve, reject) => {
        window.fbAsyncInit = () => {
            window.FB.init({ appId, autoLogAppEvents: true, xfbml: false, version });
            resolve(window.FB);
        };
        const script = document.createElement('script');
        script.src = 'https://connect.facebook.net/en_US/sdk.js';
        script.async = true;
        script.defer = true;
        script.crossOrigin = 'anonymous';
        script.onerror = () => {
            sdkPromise = null;
            reject(new Error('Facebook SDK failed to load'));
        };
        document.body.appendChild(script);
    });

    return sdkPromise;
}

export default function EmbeddedSignupButton({ config, connected = false }) {
    const [busy, setBusy] = useState(false);
    const session = useRef({ waba_id: null, phone_number_id: null });

    useEffect(() => {
        if (config?.enabled) {
            loadFacebookSdk(config.app_id, config.graph_version).catch(() => {});
        }
    }, [config?.enabled, config?.app_id, config?.graph_version]);

    useEffect(() => {
        const onMessage = (event) => {
            if (!FB_ORIGIN.test(event.origin)) return;
            let data = event.data;
            if (typeof data === 'string') {
                try {
                    data = JSON.parse(data);
                } catch {
                    return;
                }
            }
            if (data?.type !== 'WA_EMBEDDED_SIGNUP') return;

            if (data.event === 'FINISH' || data.event === 'FINISH_ONLY_WABA') {
                session.current = {
                    waba_id: data.data?.waba_id || null,
                    phone_number_id: data.data?.phone_number_id || null,
                };
            } else if (data.event === 'CANCEL') {
                toast.error(
                    data.data?.current_step
                        ? `WhatsApp setup was closed at step: ${data.data.current_step}`
                        : 'WhatsApp setup was closed before finishing.',
                );
            } else if (data.event === 'ERROR') {
                toast.error(data.data?.error_message || 'Meta reported an error during setup.');
            }
        };

        window.addEventListener('message', onMessage);
        return () => window.removeEventListener('message', onMessage);
    }, []);

    if (!config?.enabled) {
        return null;
    }

    const submit = (code) => {
        // The session-info message can arrive just after the login callback.
        setTimeout(() => {
            router.post(
                route('whatsapp.embedded-signup'),
                {
                    code,
                    waba_id: session.current.waba_id,
                    phone_number_id: session.current.phone_number_id,
                },
                {
                    preserveScroll: true,
                    onFinish: () => setBusy(false),
                },
            );
        }, 1200);
    };

    const launch = async () => {
        setBusy(true);
        session.current = { waba_id: null, phone_number_id: null };

        let FB;
        try {
            FB = await loadFacebookSdk(config.app_id, config.graph_version);
        } catch {
            setBusy(false);
            toast.error('Could not load Facebook. Disable ad-blockers for this page and try again.');
            return;
        }

        // FB.login rejects async callbacks, so keep this one synchronous.
        FB.login(
            (response) => {
                const code = response?.authResponse?.code;
                if (!code) {
                    setBusy(false);
                    return;
                }
                submit(code);
            },
            {
                config_id: config.config_id,
                response_type: 'code',
                override_default_response_type: true,
                extras: { setup: {}, sessionInfoVersion: '3' },
            },
        );
    };

    return (
        <PrimaryButton type="button" onClick={launch} disabled={busy}>
            {busy ? 'Connecting…' : connected ? 'Reconnect WhatsApp' : 'Connect WhatsApp'}
        </PrimaryButton>
    );
}
