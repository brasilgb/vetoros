import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import '../css/app.css';
import { initializeTheme } from './hooks/use-appearance';
import { initVetorchatWidgetVisibility } from './lib/vetorchat-widget';

const appName = import.meta.env.VITE_APP_NAME || 'VetorOS';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        initVetorchatWidgetVisibility(props.initialPage.component);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#155DFC',
    },
});

initializeTheme();
