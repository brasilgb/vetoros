import { MessageCircle } from 'lucide-react';
import { whatsappLink } from './site-contact';

export function WhatsAppFloat() {
    return (
        <a
            href={whatsappLink()}
            target="_blank"
            rel="noopener noreferrer"
            aria-label="Falar com a equipe VetorOS pelo WhatsApp"
            title="Falar no WhatsApp"
            className="fixed right-5 bottom-5 z-50 flex h-14 w-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-[0_10px_30px_rgba(0,0,0,0.25)] transition hover:-translate-y-1 hover:bg-[#20bd5a] sm:right-8 sm:bottom-8"
        >
            <MessageCircle className="h-7 w-7" fill="currentColor" strokeWidth={0} />
            <span className="sr-only">Falar no WhatsApp</span>
        </a>
    );
}
