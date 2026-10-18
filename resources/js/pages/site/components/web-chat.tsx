import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { MessageCircle, Send, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { contactLink, formatWhatsappNumber, useContactChannel } from './site-contact';

type Message = { id: number | string; sender: string; body: string; created_at?: string };
type Session = { id: string; expires: number; identified?: boolean; dismissed?: boolean };
type ContactForm = { name: string; whatsapp: string; subject: string; email: string; company_name: string; whatsapp_consent: boolean };

// Assuntos aceitos pelo VetorLeads (VETOR-CHAT-11). Só "comercial" pode virar oportunidade.
const SUBJECTS: [string, string][] = [
    ['comercial', 'Conhecer ou contratar'],
    ['suporte', 'Suporte (já sou cliente)'],
    ['financeiro', 'Financeiro'],
    ['outro', 'Outro assunto'],
];
const EMPTY_CONTACT: ContactForm = { name: '', whatsapp: '', subject: '', email: '', company_name: '', whatsapp_consent: false };

function contactErrors(contact: ContactForm) {
    const errors: Partial<Record<keyof ContactForm, string>> = {};
    if (contact.name.trim().length < 2) errors.name = 'Informe seu nome.';
    const digits = contact.whatsapp.replace(/\D/g, '');
    if (digits.length < 10 || digits.length > 13) errors.whatsapp = 'Informe o WhatsApp com DDD.';
    if (!contact.subject) errors.subject = 'Escolha o assunto.';

    return errors;
}
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
    const [contact, setContact] = useState<ContactForm>(EMPTY_CONTACT);
    const [fieldErrors, setFieldErrors] = useState<Partial<Record<keyof ContactForm, string>>>({});
    const [identifiedName, setIdentifiedName] = useState('');
    const [savingContact, setSavingContact] = useState(false);
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
                if (response.data.identified === true && !session.identified) saveSession({ ...session, identified: true });
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
    }, [messages, open, identifyOpen]);

    const saveSession = (next: Session) => {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
        setSession(next);
    };

    // Pede a identificação depois da primeira mensagem, sem bloquear a conversa.
    const askIdentification = Boolean(session && !session.identified && (identifyOpen || !session.dismissed));

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
                saveSession({ id: response.data.conversation_id as string, expires: Date.now() + 30 * 86400000 });
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
        if (!session || savingContact) return;
        const errors = contactErrors(contact);
        setFieldErrors(errors);
        if (Object.keys(errors).length) return;
        setSavingContact(true);
        try {
            await axios.patch(`/chat/conversations/${session.id}/contact`, contact);
            saveSession({ ...session, identified: true });
            setIdentifiedName(contact.name.trim().split(' ')[0]);
            setIdentifyOpen(false);
            setError('');
        } catch (reason) {
            const serverErrors = axios.isAxiosError(reason) ? reason.response?.data?.errors : null;
            if (serverErrors) {
                setFieldErrors(
                    Object.fromEntries(Object.entries(serverErrors as Record<string, string[]>).map(([field, messages]) => [field, messages[0]])),
                );
            } else {
                setError('Não foi possível salvar seus dados. Tente novamente.');
            }
        } finally {
            setSavingContact(false);
        }
    };

    const dismissIdentification = () => {
        if (session) saveSession({ ...session, dismissed: true });
        setIdentifyOpen(false);
        setFieldErrors({});
    };

    const field = (name: keyof ContactForm) =>
        fieldErrors[name] ? (
            <span id={`web-chat-${name}-error`} className="text-[11px] text-red-700">
                {fieldErrors[name]}
            </span>
        ) : null;

    if (webChatSettings?.web_chat_enabled === false) return null;

    return (
        <div data-web-chat data-open={open} className="fixed right-5 bottom-5 z-50 sm:right-8 sm:bottom-8">
            {open && (
                <section
                    aria-label="Atendimento VetorOS"
                    className="mb-3 flex h-[min(70vh,34rem)] w-[min(92vw,24rem)] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl"
                >
                    <header className="flex items-center justify-between bg-slate-900 px-4 py-3 text-white">
                        <div>
                            <p className="font-semibold">Atendimento VetorOS</p>
                            <p className="text-xs text-slate-300">Vendas e suporte, aqui pelo site</p>
                        </div>
                        <button type="button" onClick={() => setOpen(false)} aria-label="Fechar chat" className="rounded p-2 hover:bg-white/10">
                            <X size={18} />
                        </button>
                    </header>
                    <div className="flex-1 space-y-3 overflow-y-auto bg-slate-50 p-4" aria-live="polite">
                        <p className="max-w-[90%] rounded-2xl rounded-tl-sm bg-white p-3 text-sm text-slate-700 shadow-sm">
                            {webChatSettings?.web_chat_welcome_message ||
                                'Olá! Envie sua mensagem e nossa equipe responderá por aqui. Não precisa se cadastrar para começar.'}
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
                        {identifiedName && (
                            <p className="max-w-[90%] rounded-2xl rounded-tl-sm bg-white p-3 text-sm text-slate-700 shadow-sm">
                                Obrigado, {identifiedName}! Seus dados foram registrados e a equipe continua por aqui.
                                {contact.whatsapp_consent && contactLink(channel) && (
                                    <a
                                        className="mt-2 block font-medium text-blue-700 underline"
                                        href={contactLink(channel)!}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Prefiro continuar pelo WhatsApp
                                    </a>
                                )}
                            </p>
                        )}
                        {askIdentification && (
                            <form
                                onSubmit={identify}
                                noValidate
                                aria-label="Identificação para retorno"
                                className="space-y-2 rounded-2xl rounded-tl-sm border border-slate-200 bg-white p-3 text-sm text-slate-700 shadow-sm"
                            >
                                <p className="font-medium">Para não perdermos o contato, como podemos te chamar?</p>
                                <label className="block space-y-1">
                                    <span className="text-xs text-slate-500">Nome</span>
                                    <input
                                        value={contact.name}
                                        maxLength={120}
                                        autoComplete="name"
                                        aria-invalid={Boolean(fieldErrors.name)}
                                        aria-describedby="web-chat-name-error"
                                        onChange={(event) => setContact({ ...contact, name: event.target.value })}
                                        className="w-full rounded-lg border px-2 py-1.5 text-sm"
                                    />
                                    {field('name')}
                                </label>
                                <label className="block space-y-1">
                                    <span className="text-xs text-slate-500">WhatsApp com DDD</span>
                                    <input
                                        value={contact.whatsapp}
                                        inputMode="tel"
                                        autoComplete="tel-national"
                                        placeholder="(51) 99999-9999"
                                        maxLength={24}
                                        aria-invalid={Boolean(fieldErrors.whatsapp)}
                                        aria-describedby="web-chat-whatsapp-error"
                                        onChange={(event) => setContact({ ...contact, whatsapp: formatWhatsappNumber(event.target.value) })}
                                        className="w-full rounded-lg border px-2 py-1.5 text-sm"
                                    />
                                    {field('whatsapp')}
                                </label>
                                <label className="block space-y-1">
                                    <span className="text-xs text-slate-500">Assunto</span>
                                    <select
                                        value={contact.subject}
                                        aria-invalid={Boolean(fieldErrors.subject)}
                                        aria-describedby="web-chat-subject-error"
                                        onChange={(event) => setContact({ ...contact, subject: event.target.value })}
                                        className="w-full rounded-lg border bg-white px-2 py-1.5 text-sm"
                                    >
                                        <option value="">Escolha</option>
                                        {SUBJECTS.map(([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                    {field('subject')}
                                </label>
                                <details className="text-xs">
                                    <summary className="cursor-pointer text-slate-500">E-mail e empresa (opcional)</summary>
                                    <div className="mt-2 grid gap-2">
                                        <input
                                            aria-label="E-mail"
                                            placeholder="E-mail"
                                            type="email"
                                            autoComplete="email"
                                            maxLength={190}
                                            value={contact.email}
                                            onChange={(event) => setContact({ ...contact, email: event.target.value })}
                                            className="rounded-lg border px-2 py-1.5 text-sm"
                                        />
                                        {field('email')}
                                        <input
                                            aria-label="Empresa"
                                            placeholder="Empresa"
                                            autoComplete="organization"
                                            maxLength={190}
                                            value={contact.company_name}
                                            onChange={(event) => setContact({ ...contact, company_name: event.target.value })}
                                            className="rounded-lg border px-2 py-1.5 text-sm"
                                        />
                                    </div>
                                </details>
                                <label className="flex items-start gap-2 text-xs">
                                    <input
                                        type="checkbox"
                                        checked={contact.whatsapp_consent}
                                        onChange={(event) => setContact({ ...contact, whatsapp_consent: event.target.checked })}
                                        className="mt-0.5"
                                    />
                                    <span>Autorizo o contato pelo WhatsApp sobre este atendimento.</span>
                                </label>
                                <p className="text-[11px] text-slate-500">
                                    Usamos estes dados apenas para responder a este atendimento. Você pode pedir a exclusão a qualquer momento. Veja a{' '}
                                    <a className="underline" href="/privacidade" target="_blank" rel="noreferrer">
                                        Política de Privacidade
                                    </a>
                                    .
                                </p>
                                <div className="flex gap-2">
                                    <button
                                        disabled={savingContact}
                                        className="flex-1 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50"
                                    >
                                        {savingContact ? 'Salvando...' : 'Enviar dados'}
                                    </button>
                                    <button type="button" onClick={dismissIdentification} className="rounded-lg border px-3 py-1.5 text-xs">
                                        Agora não
                                    </button>
                                </div>
                            </form>
                        )}
                        <div ref={bottom} />
                    </div>
                    {session && !session.identified && !askIdentification && (
                        <div className="border-t px-3 py-2">
                            <button type="button" onClick={() => setIdentifyOpen(true)} className="text-xs text-blue-700 underline">
                                Informar meus dados para retorno
                            </button>
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
                        Suas mensagens são tratadas conforme a{' '}
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
                    aria-label={open ? 'Fechar atendimento' : 'Abrir atendimento'}
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
