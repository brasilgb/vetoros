import { toastWarning } from '@/components/app-toast-messages';
import { normalizeWhatsappPhone } from '@/Utils/mask';
import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import React, { useState } from 'react';

export type WhatsAppButtonProps = {
    phone: string;
    customerName: string;
    orderId: number;
    orderNumber?: string;
    status?: string | number;
    feedback?: boolean;
    context?: 'default' | 'budget_follow_up' | 'pending_payment';
    amountDue?: number;
    daysPending?: number;

    whats?: {
        generatedbudget?: string;
        servicecompleted?: string;
        feedback?: string;
        defaultmessage?: string;
        budgetfollowup?: string;
        pendingpayment?: string;
        tracking_token?: string;
        public_access_key?: string;
        public_access_key_required?: boolean;
    };

    className?: string;
};

const STATUS_BUDGET = 3;
const STATUS_COMPLETED = 7;
const STATUS_OPEN = 1;

const getGreeting = () => {
    const hour = new Date().getHours();
    if (hour < 12) return 'Bom dia';
    if (hour < 18) return 'Boa tarde';
    return 'Boa noite';
};

const normalizeStatus = (status?: string | number) => Number(status ?? 0);

const buildTrackingUrl = (trackingToken?: string) => {
    if (!trackingToken) return '';

    const origin = typeof window !== 'undefined' ? window.location.origin : 'https://vetoros.com.br';
    return `${origin}/os/${trackingToken}?preview=whatsapp`;
};

const applyTemplate = (template: string, values: Record<string, string>) => {
    return template.replace(/\{\{\s*([^}]+?)\s*\}\}/g, (_, rawKey) => {
        const normalizedKey = normalizePlaceholderKey(rawKey);
        return values[normalizedKey] ?? '';
    });
};

const normalizePlaceholderKey = (key: string) =>
    key
        .trim()
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, '_')
        .replace(/-/g, '_');

const hasPlaceholder = (template: string, key: string) => {
    const target = normalizePlaceholderKey(key);
    const placeholders = template.match(/\{\{\s*([^}]+?)\s*\}\}/g) || [];

    return placeholders.some((placeholder) => {
        const rawKey = placeholder.replace('{{', '').replace('}}', '');
        return normalizePlaceholderKey(rawKey) === target;
    });
};

const withGreeting = (greeting: string, customerName: string, content: string) => `${greeting}, ${customerName}!\n${content}`;

const normalizeWhatsAppLineBreaks = (message: string) => message.replace(/\n{2,}/g, '\n').trim();

const escapeRegExp = (value: string) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const capitalizeFirstLetter = (value: string) => value.charAt(0).toUpperCase() + value.slice(1);

const applyOpenOrderGreeting = (message: string, greeting: string, customerName: string) => {
    const customerPattern = escapeRegExp(customerName.trim());
    const openingPattern = new RegExp(`^\\s*(ol[aá]|oi)\\s*,?\\s*${customerPattern}\\s*[,!.:-]?\\s*`, 'i');

    if (!openingPattern.test(message)) {
        return message;
    }

    const content = message.replace(openingPattern, '').trim();

    if (!content) {
        return `${greeting}, ${customerName}!`;
    }

    return `${greeting}, ${customerName}!\n${capitalizeFirstLetter(content)}`;
};

// Chave do modelo usado: o backend a registra na comunicação (e liga ao orçamento quando for o caso).
type TemplateKey = 'budget_follow_up' | 'pending_payment' | 'feedback' | 'generatedbudget' | 'servicecompleted' | 'defaultmessage';

const getTemplateKeyForContext = ({
    status,
    feedback,
    context,
    whats,
}: Pick<WhatsAppButtonProps, 'status' | 'feedback' | 'context' | 'whats'>): TemplateKey | null => {
    const currentStatus = normalizeStatus(status);

    if (context === 'budget_follow_up' && whats?.budgetfollowup) {
        return 'budget_follow_up';
    }

    if (context === 'pending_payment' && whats?.pendingpayment) {
        return 'pending_payment';
    }

    // prioridade máxima: janela de feedback
    if (feedback) {
        return whats?.feedback ? 'feedback' : null;
    }

    // status com template específico
    if (currentStatus === STATUS_BUDGET && whats?.generatedbudget) {
        return 'generatedbudget';
    }

    if (currentStatus === STATUS_COMPLETED && whats?.servicecompleted) {
        return 'servicecompleted';
    }

    // demais status usam mensagem padrão
    if (whats?.defaultmessage) {
        return 'defaultmessage';
    }

    return null;
};

const TEMPLATE_FIELDS: Record<
    TemplateKey,
    'budgetfollowup' | 'pendingpayment' | 'feedback' | 'generatedbudget' | 'servicecompleted' | 'defaultmessage'
> = {
    budget_follow_up: 'budgetfollowup',
    pending_payment: 'pendingpayment',
    feedback: 'feedback',
    generatedbudget: 'generatedbudget',
    servicecompleted: 'servicecompleted',
    defaultmessage: 'defaultmessage',
};

const getTemplateForContext = (props: Pick<WhatsAppButtonProps, 'status' | 'feedback' | 'context' | 'whats'>): string | null => {
    const key = getTemplateKeyForContext(props);

    return key ? (props.whats?.[TEMPLATE_FIELDS[key]] ?? null) : null;
};

const formatTemplateMessage = ({
    template,
    greeting,
    customerName,
    values,
    status,
}: {
    template: string;
    greeting: string;
    customerName: string;
    values: Record<string, string>;
    status?: string | number;
}) => {
    const parsed = applyTemplate(template, values).trim();
    if (!parsed) return '';

    if (hasPlaceholder(template, 'saudacao') || hasPlaceholder(template, 'cliente')) {
        if (normalizeStatus(status) === STATUS_OPEN && !hasPlaceholder(template, 'saudacao')) {
            return applyOpenOrderGreeting(parsed, greeting, customerName);
        }

        return parsed;
    }

    return withGreeting(greeting, customerName, parsed);
};

const formatCurrency = (value?: number) => {
    if (!value || Number(value) <= 0) return 'R$ 0,00';

    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(Number(value));
};

export const buildMessage = ({
    customerName,
    orderNumber,
    status,
    feedback,
    context,
    amountDue,
    daysPending,
    whats,
}: Omit<WhatsAppButtonProps, 'phone' | 'className' | 'orderId'>) => {
    const currentStatus = normalizeStatus(status);
    const greeting = getGreeting();
    const trackingUrl = buildTrackingUrl(whats?.tracking_token);
    const templateValues = {
        saudacao: greeting,
        saudação: greeting,
        cliente: customerName,
        ordem: String(orderNumber ?? ''),
        link_os: trackingUrl,
        status: String(currentStatus),
        saldo: formatCurrency(amountDue),
        dias_pendentes: String(daysPending ?? 0),
    };

    const selectedTemplate = getTemplateForContext({ status, feedback, context, whats });
    if (!selectedTemplate) return '';

    const message = normalizeWhatsAppLineBreaks(
        formatTemplateMessage({
            template: selectedTemplate,
            greeting,
            customerName,
            values: templateValues,
            status,
        }),
    );

    return whats?.public_access_key_required && whats.public_access_key ? `${message}\nChave de acesso: ${whats.public_access_key}` : message;
};

const canSendWhatsAppMessage = ({ status, feedback, context, whats }: Pick<WhatsAppButtonProps, 'status' | 'feedback' | 'context' | 'whats'>) => {
    return Boolean(getTemplateForContext({ status, feedback, context, whats }));
};

const getWhatsAppDisabledReason = ({
    phone,
    status,
    feedback,
    context,
    whats,
}: Pick<WhatsAppButtonProps, 'phone' | 'status' | 'feedback' | 'context' | 'whats'>) => {
    if (!phone || !phone.replace(/\D/g, '')) {
        return 'Cliente sem WhatsApp cadastrado.';
    }

    const currentStatus = normalizeStatus(status);
    if (context === 'budget_follow_up' && !whats?.budgetfollowup) {
        return 'Mensagem não configurada para orçamento parado.';
    }

    if (context === 'pending_payment' && !whats?.pendingpayment) {
        return 'Mensagem não configurada para cobrança pendente.';
    }

    if (feedback && !whats?.feedback) {
        return 'Mensagem não configurada para este status.';
    }

    if (currentStatus === STATUS_BUDGET && !whats?.generatedbudget) {
        return 'Mensagem não configurada para este status.';
    }

    if (currentStatus === STATUS_COMPLETED && !whats?.servicecompleted) {
        return 'Mensagem não configurada para este status.';
    }

    if (!canSendWhatsAppMessage({ status, feedback, context, whats })) {
        return 'Configure uma mensagem padrão para os demais status.';
    }

    return '';
};

export const WhatsAppButton: React.FC<WhatsAppButtonProps> = ({
    phone,
    customerName,
    orderId,
    orderNumber,
    status,
    feedback,
    context,
    amountDue,
    daysPending,
    whats,
    className,
}) => {
    const [isSending, setIsSending] = useState(false);
    const canSend = canSendWhatsAppMessage({ status, feedback, context, whats });
    const disabledReason = getWhatsAppDisabledReason({ phone, status, feedback, context, whats });
    const isDisabled = !canSend || Boolean(disabledReason) || isSending;

    const handleClick = () => {
        if (!phone || !canSend || isDisabled) return;

        const cleanPhone = normalizeWhatsappPhone(phone);

        if (cleanPhone.length < 12) {
            toastWarning('Número de WhatsApp inválido para envio.');
            return;
        }

        const message = buildMessage({
            customerName,
            orderNumber,
            status,
            feedback,
            context,
            amountDue,
            daysPending,
            whats,
        });

        if (!message.trim()) return;

        // Envia pelo WhatsApp conectado do tenant (via WAHA), não mais por link wa.me manual.
        router.post(
            route('app.orders.whatsapp.send', orderId),
            { message, template: getTemplateKeyForContext({ status, feedback, context, whats }) },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setIsSending(true),
                onFinish: () => setIsSending(false),
            },
        );
    };

    return (
        <button
            onClick={handleClick}
            disabled={isDisabled}
            title={isSending ? 'Enviando mensagem...' : isDisabled ? disabledReason : 'Enviar mensagem pelo WhatsApp'}
            className={className || 'rounded-md bg-green-500 px-3 py-2 text-white disabled:cursor-not-allowed disabled:opacity-40'}
        >
            {isSending ? (
                <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" className="bi bi-whatsapp" viewBox="0 0 16 16">
                    <path d="M13.601 2.326A7.85 7.85 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.9 7.9 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.9 7.9 0 0 0 13.6 2.326zM7.994 14.521a6.6 6.6 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.56 6.56 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592m3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.065-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.73.73 0 0 0-.529.247c-.182.198-.691.677-.691 1.654s.71 1.916.81 2.049c.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.904.129 1.246.08.38-.058 1.171-.48 1.338-.943.164-.464.164-.86.114-.943-.049-.084-.182-.133-.38-.232" />
                </svg>
            )}
        </button>
    );
};
