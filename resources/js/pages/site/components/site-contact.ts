import { usePage } from '@inertiajs/react';

export type ContactChannel = {
    number: string | null;
    name: string;
    message: string;
    publicEnabled: boolean;
    internalEnabled: boolean;
    provider: 'whatsapp' | 'chat';
};

export function useContactChannel() {
    return usePage<{ contactChannel?: ContactChannel }>().props.contactChannel;
}

export function formatWhatsappNumber(value: string) {
    const digits = value.replace(/\D/g, '');
    const hasCountryCode = digits.startsWith('55') && digits.length > 11;
    const local = (hasCountryCode ? digits.slice(2) : digits).slice(0, 11);
    if (!local) return '';

    const area = local.slice(0, 2);
    const rest = local.slice(2);
    const number = rest.length > 8 ? `${rest.slice(0, 5)}-${rest.slice(5)}` : rest.length > 4 ? `${rest.slice(0, 4)}-${rest.slice(4)}` : rest;

    return `${hasCountryCode ? '+55 ' : ''}${local.length > 2 ? `(${area}) ` : ''}${number}`;
}

export function contactLink(channel: ContactChannel | undefined, message?: string) {
    if (!channel?.number || !channel.publicEnabled) return null;
    if (channel.provider === 'chat') return '/chat';
    const number = channel.number.replace(/\D/g, '');
    const text = message?.trim() || channel.message;
    return `https://wa.me/${number}${text ? `?text=${encodeURIComponent(text)}` : ''}`;
}
