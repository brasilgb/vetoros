import type { route as routeFn } from 'ziggy-js';

declare global {
    const route: typeof routeFn;
    /** Versão do VetorOS (package.json "version"), injetada pelo Vite. */
    const __APP_VERSION__: string;
}
