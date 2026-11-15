import * as React from 'react';

import { Input } from '@/components/ui/input';
import {
    type MoneyValue,
    formatMoneyNumber,
    moneyFromCentsDigits,
    moneyFromDraft,
    moneyToDraft,
    normalizeMoney,
    sanitizeMoneyDraft,
} from '@/lib/money';
import { cn } from '@/lib/utils';

type MoneyInputProps = Omit<React.ComponentProps<'input'>, 'value' | 'defaultValue' | 'onChange' | 'type'> & {
    /** Valor normalizado ("1234.56"), número ou ''. */
    value: MoneyValue;
    /** Recebe sempre a string normalizada com ponto e duas casas, ou '' quando vazio. */
    onValueChange: (value: string) => void;
    /**
     * - `cents`: dígitos entram pela direita (padrão dos campos que usavam maskMoneyDot).
     * - `decimal`: digitação livre com vírgula; formata ao sair do campo.
     */
    mode?: 'cents' | 'decimal';
    allowNegative?: boolean;
    /** Mensagem de erro: marca o campo como inválido e liga o aria-describedby. */
    error?: string;
    /** Mostra o prefixo "R$" (padrão). */
    showPrefix?: boolean;
};

/** Quantos dígitos existem a partir de uma posição do texto (para reposicionar o cursor). */
function digitsAfter(text: string, position: number): number {
    return text.slice(position).replace(/\D/g, '').length;
}

/** Posição em que restam exatamente `count` dígitos à direita. */
function positionWithDigitsAfter(text: string, count: number): number {
    if (count <= 0) return text.length;

    let seen = 0;
    for (let i = text.length - 1; i >= 0; i--) {
        if (/\d/.test(text[i])) seen++;
        if (seen === count) return i;
    }

    return 0;
}

/**
 * Campo monetário em BRL (VETOR-UX-MONEY-01): mostra "1.234,56" com prefixo "R$" e
 * entrega ao formulário o valor normalizado ("1234.56"), compatível com Inertia useForm
 * e com as regras numéricas do Laravel. Colar "R$ 1.250,75" ou "1,250.75" funciona nos
 * dois modos.
 */
function MoneyInput({
    value,
    onValueChange,
    mode = 'cents',
    allowNegative = false,
    error,
    showPrefix = true,
    className,
    id,
    onBlur,
    onFocus,
    onKeyDown,
    'aria-describedby': ariaDescribedBy,
    ...props
}: MoneyInputProps) {
    const inputRef = React.useRef<HTMLInputElement>(null);
    const pendingCaret = React.useRef<number | null>(null);
    const [focused, setFocused] = React.useState(false);
    const [draft, setDraft] = React.useState(() => moneyToDraft(value));
    // Modo centavos: "-" digitado antes dos números fica visível até chegar o primeiro dígito.
    const [pendingMinus, setPendingMinus] = React.useState(false);
    const options = { allowNegative };

    const normalized = normalizeMoney(value, options);

    // Valor alterado por fora (reset do formulário, cálculo) enquanto o campo não está em edição.
    React.useEffect(() => {
        if (!focused) setDraft(moneyToDraft(normalized));
    }, [normalized, focused]);

    const centsText =
        normalized === '' ? (pendingMinus ? '-' : '') : `${normalized.startsWith('-') ? '-' : ''}${formatMoneyNumber(normalized.replace('-', ''))}`;
    const displayValue = mode === 'cents' ? centsText : focused ? draft : formatMoneyNumber(normalized);

    React.useLayoutEffect(() => {
        if (pendingCaret.current !== null && inputRef.current && document.activeElement === inputRef.current) {
            inputRef.current.setSelectionRange(pendingCaret.current, pendingCaret.current);
            pendingCaret.current = null;
        }
    });

    const handleChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        const text = event.target.value;
        const caret = event.target.selectionStart ?? text.length;

        if (mode === 'cents') {
            const next = moneyFromCentsDigits(text, options);
            const keepDigitsRight = digitsAfter(text, caret);
            const nextText = next === '' || next === '-' ? next : `${next.startsWith('-') ? '-' : ''}${formatMoneyNumber(next.replace('-', ''))}`;
            pendingCaret.current = positionWithDigitsAfter(nextText, keepDigitsRight);
            setPendingMinus(next === '-');
            onValueChange(next === '-' ? '' : next);

            return;
        }

        applyDraft(text, caret);
    };

    const applyDraft = (text: string, caret: number) => {
        const nextDraft = sanitizeMoneyDraft(text, options);
        pendingCaret.current = Math.max(0, caret - (text.length - nextDraft.length));
        setDraft(nextDraft);
        onValueChange(moneyFromDraft(nextDraft, options));
    };

    /**
     * Tecla separadora do teclado numérico: vale sempre como vírgula. Conforme o NumLock, o
     * layout e o navegador (ex.: Firefox no Linux) ela chega como ".", "," ou até "Delete",
     * e no último caso a vírgula sumia: "125,38" virava 12.538,00.
     */
    const handleKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        onKeyDown?.(event);
        if (event.defaultPrevented || event.code !== 'NumpadDecimal' || event.ctrlKey || event.metaKey || event.altKey) return;

        event.preventDefault();
        if (mode === 'cents' || props.readOnly || props.disabled) return;

        const input = event.currentTarget;
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? start;
        applyDraft(`${input.value.slice(0, start)},${input.value.slice(end)}`, start + 1);
    };

    const handlePaste = (event: React.ClipboardEvent<HTMLInputElement>) => {
        const input = event.currentTarget;
        const selectedAll = input.selectionStart === 0 && input.selectionEnd === input.value.length;
        const pasted = normalizeMoney(event.clipboardData.getData('text'), options);

        // Colar sobre o campo inteiro (ou vazio) interpreta o texto colado como valor completo.
        if (pasted === '' || !(selectedAll || input.value === '' || normalized === '0.00')) return;

        event.preventDefault();
        setDraft(moneyToDraft(pasted));
        pendingCaret.current = null;
        onValueChange(pasted);
    };

    const errorId = error && id ? `${id}-error` : undefined;

    // self-start: em grids que esticam a linha (ex.: ao lado de um Textarea), o wrapper não pode crescer,
    // senão o "R$" centralizado fica abaixo do centro do input.
    return (
        <div className="relative w-full self-start">
            {showPrefix && (
                <span aria-hidden="true" className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm">
                    R$
                </span>
            )}
            <Input
                {...props}
                ref={inputRef}
                id={id}
                type="text"
                inputMode={mode === 'cents' && !allowNegative ? 'numeric' : 'decimal'}
                autoComplete="off"
                value={displayValue}
                onChange={handleChange}
                onPaste={handlePaste}
                onKeyDown={handleKeyDown}
                onFocus={(event) => {
                    setFocused(true);
                    setDraft(moneyToDraft(normalized));
                    onFocus?.(event);
                }}
                onBlur={(event) => {
                    setFocused(false);
                    setPendingMinus(false);
                    onBlur?.(event);
                }}
                aria-invalid={error ? true : props['aria-invalid']}
                aria-describedby={[ariaDescribedBy, errorId].filter(Boolean).join(' ') || undefined}
                className={cn('tabular-nums', showPrefix && 'pl-9', className)}
            />
            {error && (
                <p id={errorId} className="sr-only">
                    {error}
                </p>
            )}
        </div>
    );
}

export { MoneyInput };
export type { MoneyInputProps };
