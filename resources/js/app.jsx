import '../css/app.css';
import './bootstrap';

import ConfirmProvider from '@/Components/ConfirmProvider';
import ErrorBoundary from '@/Components/ErrorBoundary';
import ToastProvider from '@/Components/ToastProvider';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'rankwayAI';

if (typeof window !== 'undefined' && window.location.hash === '#_=_') {
    history.replaceState(null, '', window.location.pathname + window.location.search);
}

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
            <ErrorBoundary>
                <ToastProvider>
                    <ConfirmProvider>
                        <App {...props} />
                    </ConfirmProvider>
                </ToastProvider>
            </ErrorBoundary>,
        );
    },
    progress: false,
});
