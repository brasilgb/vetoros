import { toastSuccess } from '@/components/app-toast-messages';
import { Icon } from '@/components/icon';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { MessageCircleCode, Save } from 'lucide-react';
import type { FormEvent } from 'react';
import WhatsappConnectionPanel from './connection-panel';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Mensagens Whatsapp',
        href: '#',
    },
];

type WhatsappMessageSettings = {
    id?: number;
    generatedbudget?: string;
    servicecompleted?: string;
    feedback?: string;
    defaultmessage?: string;
    budgetfollowup?: string;
    pendingpayment?: string;
    technicianschedule?: string;
};

const DEFAULT_MESSAGES = {
    generatedbudget:
        '{{ saudacao }}, {{ cliente }}!\n\nEquipamento analisado preliminarmente. Segue orçamento inicial para reparo conforme diagnóstico técnico apresentado na OS {{ ordem }}.\n\nO serviço será executado somente mediante sua aprovação. Valores e prazo podem sofrer alterações caso sejam identificadas necessidades adicionais durante o reparo.\n\nVocê pode acompanhar pelo link: {{ link_os }}',
    servicecompleted:
        '{{ saudacao }}, {{ cliente }}!\n\nSua OS {{ ordem }} foi concluída com sucesso.\n\nVocê pode acompanhar pelo link: {{ link_os }}\n\nQualquer dúvida, conte com a gente.',
    feedback:
        '{{ saudacao }}, {{ cliente }}!\n\nSua OS {{ ordem }} foi finalizada e sua opinião é muito importante para nós.\n\nAcesse sua área do cliente pelo link {{ link_os }} e deixe uma nota com um comentário rápido sobre sua experiência.\n\nSeu feedback nos ajuda a melhorar cada atendimento.',
    defaultmessage:
        '{{ saudacao }}, {{ cliente }}!\n\nAtualização da sua OS {{ ordem }}.\n\nAcompanhe pelo link: {{ link_os }}\n\nQualquer dúvida, estamos à disposição.',
    budgetfollowup:
        '{{ saudacao }}, {{ cliente }}!\n\nSeu orçamento da OS {{ ordem }} segue aguardando retorno há {{ dias_pendentes }} dias.\n\nVocê pode aprovar ou acompanhar pelo link: {{ link_os }}\n\nSe precisar de ajuda, estamos à disposição.',
    pendingpayment:
        '{{ saudacao }}, {{ cliente }}!\n\nA OS {{ ordem }} segue com saldo pendente de {{ saldo }}.\n\nVocê pode acompanhar pelo link: {{ link_os }}\n\nSe já realizou o pagamento, desconsidere esta mensagem.',
    technicianschedule:
        '{{ saudacao }}, {{ tecnico }}!\nVisita agendada para {{ data_visita }}.\nServiço: {{ servico }}.\nMateriais: {{ materiais }}.\nCliente: {{ cliente }}.\nEndereço: {{ endereco }}.',
};

const previewValues = {
    cliente: 'João da Silva',
    ordem: '1024',
    link_os: 'https://seusite.com.br/os/abc123',
    saudacao: 'Boa tarde',
    saudação: 'Boa tarde',
    saldo: 'R$ 185,00',
    dias_pendentes: '3',
    valor_orcamento: 'R$ 1.250,00',
    valor_servico: 'R$ 950,00',
    tecnico: 'Carlos Pereira',
    data_visita: '20/09/2026 14:00',
    servico: 'Manutenção preventiva',
    materiais: '1x Filtro de óleo, 2x Correia',
    endereco: 'Rua das Flores, 123 - Centro, São Paulo',
};

const normalizePlaceholderKey = (key: string) =>
    key
        .trim()
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, '_')
        .replace(/-/g, '_');

const renderPreview = (template?: string) => {
    if (!template) return '';

    return template.replace(/\{\{\s*([^}]+?)\s*\}\}/g, (_, key) => {
        const normalizedKey = normalizePlaceholderKey(String(key));

        if (normalizedKey in previewValues) {
            return previewValues[normalizedKey as keyof typeof previewValues];
        }

        return '';
    });
};

type ConnectionSettings = {
    status: 'disconnected' | 'starting' | 'qr_required' | 'connected' | 'failed';
    phone_number?: string | null;
};

export default function WhatsappMessage({
    whatsappmessage,
    connection,
}: {
    whatsappmessage: WhatsappMessageSettings;
    connection: ConnectionSettings;
}) {
    const { data, setData, patch, processing } = useForm({
        generatedbudget: whatsappmessage?.generatedbudget,
        servicecompleted: whatsappmessage?.servicecompleted,
        feedback: whatsappmessage?.feedback,
        defaultmessage: whatsappmessage?.defaultmessage,
        budgetfollowup: whatsappmessage?.budgetfollowup,
        pendingpayment: whatsappmessage?.pendingpayment,
        technicianschedule: whatsappmessage?.technicianschedule,
    });

    const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        patch(route('app.whatsapp-message.update', whatsappmessage?.id), {
            onSuccess: () => {
                toastSuccess('Sucesso', 'Mensagens para whatsapp ajustadas com sucesso');
            },
        });
    };

    const handleResetDefaults = () => {
        setData('generatedbudget', DEFAULT_MESSAGES.generatedbudget);
        setData('servicecompleted', DEFAULT_MESSAGES.servicecompleted);
        setData('feedback', DEFAULT_MESSAGES.feedback);
        setData('defaultmessage', DEFAULT_MESSAGES.defaultmessage);
        setData('budgetfollowup', DEFAULT_MESSAGES.budgetfollowup);
        setData('pendingpayment', DEFAULT_MESSAGES.pendingpayment);
        setData('technicianschedule', DEFAULT_MESSAGES.technicianschedule);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Mensagens WhatsApp" />
            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={MessageCircleCode} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Mensagens WhatsApp</h2>
                </div>
            </div>

            <div className="flex items-center justify-between p-4">
                <div></div>
            </div>

            <div className="p-4">
                <Tabs defaultValue="connection" className="space-y-4">
                    <TabsList>
                        <TabsTrigger value="connection">Conexão</TabsTrigger>
                        <TabsTrigger value="templates">Templates de mensagem</TabsTrigger>
                    </TabsList>

                    <TabsContent value="connection">
                        <WhatsappConnectionPanel connection={connection} />
                    </TabsContent>

                    <TabsContent value="templates">
                        <div className="rounded-lg border p-2">
                            <form onSubmit={handleSubmit} autoComplete="off" className="space-y-8">
                                <div className="mt-4 grid gap-4">
                                    <div className="text-muted-foreground rounded-md border border-dashed p-3 text-sm">
                                        Para usar as mensagens padrão, basta clicar em salvar. Se precisar, altere os textos conforme a necessidade.
                                        Os templates inserem automaticamente informações do cliente e da O.S. pelos placeholders:{' '}
                                        <code>{'{{ cliente }}'}</code>, <code>{'{{ ordem }}'}</code>, <code>{'{{ link_os }}'}</code>,{' '}
                                        <code>{'{{ saudacao }}'}</code>, <code>{'{{ saldo }}'}</code>, <code>{'{{ dias_pendentes }}'}</code>,{' '}
                                        <code>{'{{ valor_orçamento }}'}</code> (total do orçamento, com peças) e <code>{'{{ valor_serviço }}'}</code>{' '}
                                        (somente serviços). Se a OS não tiver valor definido, o envio fica bloqueado. O aviso de agendamento ao
                                        técnico usa também <code>{'{{ tecnico }}'}</code>, <code>{'{{ data_visita }}'}</code>,{' '}
                                        <code>{'{{ servico }}'}</code>, <code>{'{{ materiais }}'}</code> e <code>{'{{ endereco }}'}</code>.
                                    </div>

                                    <div className="grid gap-2 md:col-span-2">
                                        <Label htmlFor="generatedbudget">Orçamento gerado</Label>
                                        <Textarea
                                            id="generatedbudget"
                                            value={data.generatedbudget}
                                            onChange={(e) => setData('generatedbudget', e.target.value)}
                                        />
                                        <div className="text-muted-foreground bg-muted/40 rounded-md p-2 text-xs whitespace-pre-wrap">
                                            Prévia: {renderPreview(data.generatedbudget)}
                                        </div>
                                    </div>

                                    <div className="grid gap-2 md:col-span-2">
                                        <Label htmlFor="servicecompleted">Serviço concluído</Label>
                                        <Textarea
                                            id="servicecompleted"
                                            value={data.servicecompleted}
                                            onChange={(e) => setData('servicecompleted', e.target.value)}
                                        />
                                        <div className="text-muted-foreground bg-muted/40 rounded-md p-2 text-xs whitespace-pre-wrap">
                                            Prévia: {renderPreview(data.servicecompleted)}
                                        </div>
                                    </div>

                                    <div className="grid gap-2 md:col-span-2">
                                        <Label htmlFor="feedback">Feedback ao cliente</Label>
                                        <Textarea id="feedback" value={data.feedback} onChange={(e) => setData('feedback', e.target.value)} />
                                        <div className="text-muted-foreground bg-muted/40 rounded-md p-2 text-xs whitespace-pre-wrap">
                                            Prévia: {renderPreview(data.feedback)}
                                        </div>
                                    </div>

                                    <div className="grid gap-2 md:col-span-2">
                                        <Label htmlFor="defaultmessage">Mensagem padrão (demais status)</Label>
                                        <Textarea
                                            id="defaultmessage"
                                            value={data.defaultmessage}
                                            onChange={(e) => setData('defaultmessage', e.target.value)}
                                        />
                                        <div className="text-muted-foreground bg-muted/40 rounded-md p-2 text-xs whitespace-pre-wrap">
                                            Prévia: {renderPreview(data.defaultmessage)}
                                        </div>
                                    </div>

                                    <div className="grid gap-2 md:col-span-2">
                                        <Label htmlFor="budgetfollowup">Orçamento parado</Label>
                                        <Textarea
                                            id="budgetfollowup"
                                            value={data.budgetfollowup}
                                            onChange={(e) => setData('budgetfollowup', e.target.value)}
                                        />
                                        <div className="text-muted-foreground bg-muted/40 rounded-md p-2 text-xs whitespace-pre-wrap">
                                            Prévia: {renderPreview(data.budgetfollowup)}
                                        </div>
                                    </div>

                                    <div className="grid gap-2 md:col-span-2">
                                        <Label htmlFor="pendingpayment">Cobrança pendente</Label>
                                        <Textarea
                                            id="pendingpayment"
                                            value={data.pendingpayment}
                                            onChange={(e) => setData('pendingpayment', e.target.value)}
                                        />
                                        <div className="text-muted-foreground bg-muted/40 rounded-md p-2 text-xs whitespace-pre-wrap">
                                            Prévia: {renderPreview(data.pendingpayment)}
                                        </div>
                                    </div>

                                    <div className="grid gap-2 md:col-span-2">
                                        <Label htmlFor="technicianschedule">Aviso de agendamento ao técnico</Label>
                                        <Textarea
                                            id="technicianschedule"
                                            value={data.technicianschedule}
                                            onChange={(e) => setData('technicianschedule', e.target.value)}
                                        />
                                        <div className="text-muted-foreground bg-muted/40 rounded-md p-2 text-xs whitespace-pre-wrap">
                                            Prévia: {renderPreview(data.technicianschedule)}
                                        </div>
                                    </div>
                                </div>

                                <div className="flex justify-end gap-2">
                                    <Button type="button" variant="outline" onClick={handleResetDefaults} disabled={processing}>
                                        Restaurar modelos
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        <Save />
                                        Salvar
                                    </Button>
                                </div>
                            </form>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
