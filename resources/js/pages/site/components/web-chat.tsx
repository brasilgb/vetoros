import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { MessageCircle, Send, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { contactLink, useContactChannel } from './site-contact';

type Message = { id: number | string; sender: string; body: string; created_at?: string };
type Session = { id: string; expires: number };
type ChatSettings = {
    web_chat_enabled?: boolean;
    web_chat_welcome_message?: string | null;
    web_chat_business_hours?: string | null;
    web_chat_offline_message?: string | null;
    chat_open?: boolean;
};
const STORAGE_KEY = 'vetoros-web-chat-v1';

export function WebChat() {
    const { webChatSettings } = usePage<{ webChatSettings?: ChatSettings }>().props;
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');
    const [messages, setMessages] = useState<Message[]>([]);
    const [session, setSession] = useState<Session | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [unread, setUnread] = useState(0);
    const [identifyOpen, setIdentifyOpen] = useState(false);
    const [contact, setContact] = useState({ name: '', whatsapp: '', email: '', company_name: '' });
    const bottom = useRef<HTMLDivElement>(null);
    const channel = useContactChannel();

    useEffect(() => {
        if (webChatSettings?.web_chat_enabled === false) return;

        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                const parsed = JSON.parse(saved) as Session;
                if (parsed.expires > Date.now()) setSession(parsed);
                else localStorage.removeItem(STORAGE_KEY);
            }
        } catch {
            localStorage.removeItem(STORAGE_KEY);
        }
    }, [webChatSettings?.web_chat_enabled]);

    useEffect(() => {
        if (!session || webChatSettings?.web_chat_enabled === false) return;
        let active = true;
        const poll = async () => {
            try {
                const response = await axios.get(`/chat/conversations/${session.id}`);
                if (!active) return;
                const incoming = response.data.messages as Message[];
                setMessages((current) => {
                    const known = new Set(current.map((item) => String(item.id)));
                    const fresh = incoming.filter((item) => !known.has(String(item.id)) && item.sender === 'attendant');
                    if (!open && fresh.length) setUnread((count) => count + fresh.length);
                    return incoming;
                });
                setError('');
            } catch (reason) {
                if (!active) return;
                if (axios.isAxiosError(reason) && reason.response?.status === 404) {
                    localStorage.removeItem(STORAGE_KEY);
                    setSession(null);
                    setMessages([]);
                }
                setError('Não foi possível atualizar a conversa.');
            }
        };
        void poll();
        const timer = window.setInterval(poll, 5000);
        return () => {
            active = false;
            window.clearInterval(timer);
        };
    }, [session, open, webChatSettings?.web_chat_enabled]);

    useEffect(() => {
        bottom.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages, open]);

    const send = async (event: FormEvent) => {
        event.preventDefault();
        const body = text.trim();
        if (!body || busy) return;
        setBusy(true);
        setError('');
        try {
            const client_message_id = crypto.randomUUID();
            if (!session) {
                const response = await axios.post('/chat/conversations', { message: body, client_message_id, page_url: window.location.href });
                const next = { id: response.data.conversation_id as string, expires: Date.now() + 30 * 86400000 };
                localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
                setSession(next);
                setMessages(response.data.messages);
            } else {
                const response = await axios.post(`/chat/conversations/${session.id}/messages`, { message: body, client_message_id });
                setMessages((items) => [...items, response.data.message]);
            }
            setText('');
        } catch {
            setError('Sua mensagem não foi enviada. Tente novamente.');
        } finally {
            setBusy(false);
        }
    };

    const identify = async (event: FormEvent) => {
        event.preventDefault();
        if (!session) return;
        try {
            await axios.patch(`/chat/conversations/${session.id}/contact`, contact);
            setIdentifyOpen(false);
            setError('');
        } catch {
            setError('Não foi possível salvar seus dados. Tente novamente.');
        }
    };

    if (webChatSettings?.web_chat_enabled === false) return null;

    return (
        <div data-web-chat data-open={open} className="fixed right-5 bottom-5 z-50 sm:right-8 sm:bottom-8">
            {open && (
                <section
                    aria-label="Chat comercial VetorOS"
                    className="mb-3 flex h-[min(70vh,34rem)] w-[min(92vw,24rem)] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl"
                >
                    <header className="flex items-center justify-between bg-slate-900 px-4 py-3 text-white">
                        <div>
                            <p className="font-semibold">Atendimento VetorOS</p>
                            <p className="text-xs text-slate-300">Conversa comercial</p>
                        </div>
                        <button type="button" onClick={() => setOpen(false)} aria-label="Fechar chat" className="rounded p-2 hover:bg-white/10">
                            <X size={18} />
                        </button>
                    </header>
                    <div className="flex-1 space-y-3 overflow-y-auto bg-slate-50 p-4" aria-live="polite">
                        <p className="max-w-[90%] rounded-2xl rounded-tl-sm bg-white p-3 text-sm text-slate-700 shadow-sm">
                            {webChatSettings?.web_chat_welcome_message || 'Olá! Envie sua mensagem e nossa equipe comercial responderá por aqui.'}
                        </p>
                        {webChatSettings?.web_chat_business_hours && (
                            <p className="text-xs text-slate-500">Horário de atendimento: {webChatSettings.web_chat_business_hours}</p>
                        )}
                        {webChatSettings?.chat_open === false && webChatSettings.web_chat_offline_message && (
                            <p className="max-w-[90%] rounded-2xl rounded-tl-sm bg-white p-3 text-sm text-slate-700 shadow-sm">
                                {webChatSettings.web_chat_offline_message}
                            </p>
                        )}
                        {messages.map((message) => (
                            <div key={message.id} className={`flex ${message.sender === 'visitor' ? 'justify-end' : 'justify-start'}`}>
                                <p
                                    className={`max-w-[85%] rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap ${message.sender === 'visitor' ? 'rounded-br-sm bg-blue-700 text-white' : 'rounded-bl-sm bg-white text-slate-700 shadow-sm'}`}
                                >
                                    {message.body}
                                </p>
                            </div>
                        ))}
                        <div ref={bottom} />
                    </div>
                    {session && (
                        <div className="border-t px-3 py-2">
                            <button type="button" onClick={() => setIdentifyOpen(!identifyOpen)} className="text-xs text-blue-700 underline">
                                {identifyOpen ? 'Fechar identificação' : 'Identifique-se (opcional)'}
                            </button>
                            {identifyOpen && (
                                <form onSubmit={identify} className="mt-2 grid grid-cols-2 gap-2">
                                    <input
                                        aria-label="Nome"
                                        placeholder="Nome"
                                        maxLength={120}
                                        value={contact.name}
                                        onChange={(event) => setContact({ ...contact, name: event.target.value })}
                                        className="rounded border px-2 py-1 text-xs"
                                    />
                                    <input
                                        aria-label="WhatsApp"
                                        placeholder="WhatsApp"
                                        maxLength={24}
                                        value={contact.whatsapp}
                                        onChange={(event) => setContact({ ...contact, whatsapp: event.target.value })}
                                        className="rounded border px-2 py-1 text-xs"
                                    />
                                    <input
                                        aria-label="E-mail"
                                        placeholder="E-mail (opcional)"
                                        type="email"
                                        maxLength={190}
                                        value={contact.email}
                                        onChange={(event) => setContact({ ...contact, email: event.target.value })}
                                        className="rounded border px-2 py-1 text-xs"
                                    />
                                    <input
                                        aria-label="Empresa"
                                        placeholder="Empresa (opcional)"
                                        maxLength={190}
                                        value={contact.company_name}
                                        onChange={(event) => setContact({ ...contact, company_name: event.target.value })}
                                        className="rounded border px-2 py-1 text-xs"
                                    />
                                    <button className="col-span-2 rounded bg-slate-800 px-3 py-1.5 text-xs text-white">
                                        Salvar dados de contato
                                    </button>
                                </form>
                            )}
                        </div>
                    )}
                    {error && (
                        <p role="alert" className="px-3 pt-2 text-xs text-red-700">
                            {error}
                        </p>
                    )}
                    <form onSubmit={send} className="flex gap-2 border-t p-3">
                        <label className="sr-only" htmlFor="web-chat-message">
                            Digite sua mensagem
                        </label>
                        <input
                            id="web-chat-message"
                            value={text}
                            onChange={(event) => setText(event.target.value)}
                            maxLength={4000}
                            placeholder="Digite sua mensagem..."
                            className="min-w-0 flex-1 rounded-lg border px-3 text-sm"
                        />
                        <button
                            disabled={busy || !text.trim()}
                            aria-label="Enviar mensagem"
                            className="rounded-lg bg-blue-700 p-3 text-white disabled:opacity-50"
                        >
                            <Send size={17} />
                        </button>
                    </form>
                    <footer className="px-3 pb-3 text-center text-[11px] text-slate-500">
                        Ao conversar, você concorda com nossa{' '}
                        <a className="underline" href="/privacidade">
                            Política de Privacidade
                        </a>
                        .{' '}
                        {contactLink(channel) && (
                            <a className="ml-1 underline" href={contactLink(channel)!} target="_blank" rel="noreferrer">
                                Continuar no WhatsApp
                            </a>
                        )}
                    </footer>
                </section>
            )}
            <div className="flex justify-end">
                <button
                    type="button"
                    onClick={() => {
                        setOpen(!open);
                        setUnread(0);
                    }}
                    aria-label={open ? 'Fechar atendimento' : 'Abrir atendimento comercial'}
                    className="relative flex h-14 w-14 items-center justify-center rounded-full bg-blue-700 text-white shadow-xl transition hover:bg-blue-800"
                >
                    <MessageCircle size={25} />
                    {unread > 0 && (
                        <span className="absolute -top-1 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-xs">
                            {unread}
                        </span>
                    )}
                </button>
            </div>
        </div>
    );
}
