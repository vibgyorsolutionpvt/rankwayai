import '../css/app.css';
import './bootstrap';

import ConfirmProvider from '@/Components/ConfirmProvider';
import ToastProvider from '@/Components/ToastProvider';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'rankwayAI';

createInertiaApp({
    title: (title) => {
        if (!title) {
            return appName;
        }
        return title.includes(appName) ? title : `${title} - ${appName}`;
    },
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <ToastProvider>
                <ConfirmProvider>
                    <App {...props} />
                </ConfirmProvider>
            </ToastProvider>,
        );
    },
    progress: {
        color: '#0e9f90',
        showSpinner: false,
        delay: 80,
    },
});
