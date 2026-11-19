import { router } from '@inertiajs/react';

// O Blade só injeta o script do chat de vendas quando a primeira carga é a landing page.
// Como a navegação seguinte é feita pelo Inertia (sem recarregar), o widget continuaria
// na tela após o login. Aqui o widget é escondido fora da landing e destruído ao entrar
// no sistema, o que também encerra o polling e o heartbeat dele.
const LANDING_COMPONENT = 'site/home/index';
const WIDGET_ID = 'vetorchat-widget';

let currentComponent: string | null = null;

function syncWidget(): void {
    const widget = document.getElementById(WIDGET_ID);
    if (!widget || currentComponent === null) return;

    if (currentComponent === LANDING_COMPONENT) {
        widget.style.removeProperty('display');
        return;
    }

    if (currentComponent.startsWith('site/')) {
        widget.style.display = 'none';
        return;
    }

    widget.dispatchEvent(new Event('vetorchat:destroy'));
    widget.remove();
}

export function initVetorchatWidgetVisibility(initialComponent: string): void {
    currentComponent = initialComponent;

    // O widget monta de forma assíncrona (busca a configuração antes); se ele aparecer
    // depois de uma navegação, aplica a regra da página atual.
    new MutationObserver(syncWidget).observe(document.body, { childList: true });

    router.on('navigate', (event) => {
        currentComponent = event.detail.page.component;
        syncWidget();
    });
}
