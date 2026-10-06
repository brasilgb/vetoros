// WhatsApp comercial do VetorOS, usado em todas as chamadas do site público.
export const COMMERCIAL_WHATSAPP = '5551998931325';

export const DEFAULT_WHATSAPP_MESSAGE = 'Quero mais informações sobre VetorOS';

export const whatsappLink = (message: string = DEFAULT_WHATSAPP_MESSAGE) =>
    `https://wa.me/${COMMERCIAL_WHATSAPP}?text=${encodeURIComponent(message)}`;
