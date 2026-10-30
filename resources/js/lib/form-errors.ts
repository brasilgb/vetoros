/** Marcadores de erro: aria-invalid dos campos e as mensagens em vermelho do InputError e dos blocos de erro. */
const ERROR_SELECTOR = '[aria-invalid="true"], .text-red-500, .text-red-600';
const FIELD_SELECTOR = 'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button[role="combobox"]';

/**
 * Leva o foco ao primeiro campo com erro do formulário, na ordem em que aparece na tela,
 * e o centraliza. Sem isso o operador fica olhando a tela sem saber onde corrigir.
 */
export function focusFirstInvalidField(form: HTMLFormElement | null): void {
    const first = form?.querySelector<HTMLElement>(ERROR_SELECTOR);
    if (!form || !first) return;

    let field: HTMLElement | null = first.matches(FIELD_SELECTOR) ? first : null;

    // A mensagem fica depois do controle dentro do bloco do campo: sobe até achar o controle.
    for (let scope = first.parentElement; scope && scope !== form && !field; scope = scope.parentElement) {
        field = scope.querySelector<HTMLElement>(FIELD_SELECTOR);
    }

    if (!field) return;

    field.focus({ preventScroll: true });
    field.scrollIntoView({ block: 'center', behavior: 'smooth' });
}
