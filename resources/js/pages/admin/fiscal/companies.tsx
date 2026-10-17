import AppPagination from '@/components/app-pagination';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AdminLayout from '@/layouts/admin/admin-layout';
import { BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Loader2, Settings2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { FiscalHeader, formatDate, Paginated, statusLabels } from './fiscal-tabs';

type Model = 'nfe' | 'nfce' | 'nfse';

type CompanyRow = {
    id: number;
    name: string;
    cnpj: string;
    emission_enabled: boolean;
    allowed: Record<Model, boolean>;
    tenant_enabled: Record<Model, boolean>;
    registration_status: string;
    registered_on_spedy: boolean;
    registered_at?: string | null;
    spedy_company_id?: string | null;
    spedy_environment?: string | null;
    environment_mismatch: boolean;
    last_synced_at?: string | null;
    registration_error?: string | null;
    certificate_subject?: string | null;
    certificate_expires_at?: string | null;
    emission_environment: string;
    production_released_at?: string | null;
    tax_settings_confirmed_at?: string | null;
    blockers: Record<Model, string | null> | null;
    homologation_authorized: number;
};

type Flash = { success?: string; error?: string };
type Result = { type: 'success' | 'error'; message: string };
type Action = 'update' | 'register' | 'check';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('admin.dashboard') },
    { title: 'Fiscal', href: route('admin.fiscal.integration') },
    { title: 'Empresas emissoras', href: '#' },
];

const models: Model[] = ['nfe', 'nfce', 'nfse'];
const modelNames: Record<Model, string> = { nfe: 'NF-e', nfce: 'NFC-e', nfse: 'NFS-e' };
const environmentLabels: Record<string, string> = { sandbox: 'Sandbox', production: 'Produção' };

// Contraste dos switches no tema escuro: trilho desligado visível sobre o fundo escuro do modal.
// Tema escuro: borda clara no trilho desligado garante contraste acima de 3:1 com o fundo (WCAG 1.4.11).
const switchClass = 'dark:data-[state=unchecked]:bg-slate-600 dark:data-[state=unchecked]:border-slate-300';

/** Estado real de cada modelo: liberação do RootAdmin não significa empresa pronta para emitir. */
function modelState(row: CompanyRow, model: Model): { label: string; ready: boolean } {
    if (!row.emission_enabled) return { label: 'Fiscal não habilitado', ready: false };
    if (!row.allowed[model]) return { label: 'Não liberada', ready: false };
    const blocker = row.blockers?.[model];
    if (blocker) return { label: blocker, ready: false };
    return row.emission_environment === 'production' && row.production_released_at
        ? { label: 'Apta à emissão em produção', ready: true }
        : { label: 'Pronta para homologação', ready: true };
}

/** Situação do cadastro Spedy resumida para a tabela: Cadastrada, Pendente ou Erro. */
function registration(row: CompanyRow): { label: string; tone: 'ok' | 'pending' | 'error'; detail: string } {
    if (row.registered_on_spedy) return { label: 'Cadastrada', tone: 'ok', detail: 'Cadastrada' };
    if (row.environment_mismatch) return { label: 'Erro', tone: 'error', detail: 'Vínculo de outro ambiente' };
    if (row.registration_status === 'error') return { label: 'Erro', tone: 'error', detail: 'Erro no cadastro' };
    return { label: 'Pendente', tone: 'pending', detail: statusLabels[row.registration_status] ?? row.registration_status };
}

function RegistrationBadge({ row }: { row: CompanyRow }) {
    const state = registration(row);
    if (state.tone === 'ok') return <Badge className="bg-emerald-600 text-white">{state.label}</Badge>;
    if (state.tone === 'error') return <Badge variant="destructive">{state.label}</Badge>;
    return <Badge variant="secondary">{state.label}</Badge>;
}

function certificate(row: CompanyRow): { label: string; valid: boolean } {
    if (!row.certificate_expires_at) return { label: 'Sem certificado confirmado', valid: false };
    const expired = new Date(row.certificate_expires_at).getTime() <= Date.now();
    return { label: `${expired ? 'Vencido em' : 'Vigente até'} ${formatDate(row.certificate_expires_at)}`, valid: !expired };
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="grid grid-cols-1 gap-1 py-2 sm:grid-cols-3 sm:gap-4">
            <dt className="text-muted-foreground text-sm">{label}</dt>
            <dd className="text-sm break-words sm:col-span-2">{children}</dd>
        </div>
    );
}

export default function FiscalCompanies({
    tenants,
    search,
    platformConfigured,
    platformEnvironment,
}: {
    tenants: Paginated<CompanyRow>;
    search: string;
    platformConfigured: boolean;
    platformEnvironment: string;
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [term, setTerm] = useState(search ?? '');
    const [selectedId, setSelectedId] = useState<number | null>(null);
    // O modal é controlado (sem DialogTrigger): o foco volta manualmente ao botão que o abriu.
    const trigger = useRef<HTMLElement | null>(null);
    const productionButton = useRef<HTMLButtonElement | null>(null);
    const [tab, setTab] = useState('general');
    const [busy, setBusy] = useState<Action | null>(null);
    const busyRef = useRef(false);
    const [result, setResult] = useState<Result | null>(null);
    const [confirm, setConfirm] = useState<'release' | 'revoke' | null>(null);
    const [password, setPassword] = useState('');

    // A linha vem sempre das props: depois de cada ação o modal mostra os dados atualizados da mesma empresa.
    const row = tenants.data.find((tenant) => tenant.id === selectedId) ?? null;

    const open = (tenant: CompanyRow) => {
        trigger.current = document.activeElement as HTMLElement | null;
        setSelectedId(tenant.id);
        setTab('general');
        setResult(null);
    };

    const close = (value: boolean) => {
        if (value || busy) return;
        setSelectedId(null);
        setConfirm(null);
        setPassword('');
    };

    /** preserveState mantém o modal e a aba; o retorno (flash) aparece também dentro do modal. */
    const visit = (action: Action, send: (options: object) => void, onDone?: () => void) => {
        // Uma ação por vez. Os controles não ficam `disabled` durante o envio para não perderem o foco do teclado.
        if (busyRef.current) return;
        busyRef.current = true;
        setResult(null);
        send({
            preserveState: true,
            preserveScroll: true,
            onStart: () => setBusy(action),
            onFinish: () => {
                busyRef.current = false;
                setBusy(null);
            },
            onSuccess: (page: { props: { flash?: Flash } }) => {
                const flash = page.props.flash ?? {};
                if (flash.error) setResult({ type: 'error', message: flash.error });
                else if (flash.success) setResult({ type: 'success', message: flash.success });
                onDone?.();
            },
        });
    };

    const save = (target: CompanyRow, changes: Partial<Record<string, boolean>>, secret?: string, onDone?: () => void) =>
        visit(
            'update',
            (options) =>
                router.put(
                    route('admin.fiscal.companies.update', target.id),
                    {
                        emission_enabled: target.emission_enabled,
                        nfe_allowed: target.allowed.nfe,
                        nfce_allowed: target.allowed.nfce,
                        nfse_allowed: target.allowed.nfse,
                        production_released: Boolean(target.production_released_at),
                        ...changes,
                        password: secret,
                    },
                    options,
                ),
            onDone,
        );

    const confirmProduction = () => {
        if (!row || !confirm) return;
        if (confirm === 'revoke') {
            save(row, { production_released: false }, undefined, () => setConfirm(null));
            return;
        }
        // Senha errada volta como erro de validação (onError): o diálogo fica aberto para nova tentativa.
        save(row, { production_released: true }, password, () => {
            setConfirm(null);
            setPassword('');
        });
    };

    const register = (target: CompanyRow) =>
        visit('register', (options) => router.post(route('admin.fiscal.companies.register', target.id), {}, options));
    const check = (target: CompanyRow) => visit('check', (options) => router.post(route('admin.fiscal.companies.check', target.id), {}, options));

    const cert = row ? certificate(row) : null;
    const certificateBlockers = row?.blockers
        ? Array.from(new Set(models.map((model) => row.blockers?.[model]).filter((b): b is string => Boolean(b && /certificad/i.test(b)))))
        : [];

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Fiscal — Empresas emissoras" />
            <FiscalHeader current="admin.fiscal.companies.index" />

            <div className="space-y-4 p-4">
                {!platformConfigured && (
                    <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                        A integração Spedy não está configurada: nenhuma empresa pode emitir.
                    </p>
                )}
                <Card>
                    <CardHeader>
                        <CardTitle>Empresas emissoras</CardTitle>
                        <CardDescription>
                            A emissão fica desabilitada até a liberação aqui. Use <strong>Configurações</strong> para habilitar o fiscal, liberar
                            modelos, conferir o certificado e cadastrar a empresa na Spedy. Ambiente da plataforma:{' '}
                            <strong>{environmentLabels[platformEnvironment] ?? platformEnvironment}</strong>.
                        </CardDescription>
                        <form
                            role="search"
                            className="flex max-w-md gap-2 pt-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.get(route('admin.fiscal.companies.index'), { search: term }, { preserveState: true });
                            }}
                        >
                            <Input
                                aria-label="Buscar por nome ou CNPJ"
                                placeholder="Buscar por nome ou CNPJ"
                                value={term}
                                onChange={(e) => setTerm(e.target.value)}
                            />
                            <Button type="submit" variant="outline">
                                Buscar
                            </Button>
                        </form>
                    </CardHeader>
                    <CardContent>
                        {tenants.data.length === 0 ? (
                            <p className="text-muted-foreground rounded-md border border-dashed p-6 text-center text-sm">
                                {search ? `Nenhuma empresa encontrada para "${search}".` : 'Nenhuma empresa cadastrada.'}
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Empresa</TableHead>
                                        <TableHead>Cadastro Spedy</TableHead>
                                        <TableHead>Ambiente</TableHead>
                                        <TableHead className="text-right">Ação</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {tenants.data.map((tenant) => (
                                        <TableRow key={tenant.id}>
                                            <TableCell>
                                                <div className="font-medium">{tenant.name}</div>
                                                <div className="text-muted-foreground text-xs">{tenant.cnpj || 'sem CNPJ'}</div>
                                            </TableCell>
                                            <TableCell>
                                                <RegistrationBadge row={tenant} />
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {tenant.emission_environment === 'production' ? 'Produção' : 'Homologação'}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => open(tenant)}
                                                    aria-label={`Configurações de ${tenant.name}`}
                                                >
                                                    <Settings2 className="size-4" />
                                                    Configurações
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                        <AppPagination data={tenants} />
                    </CardContent>
                </Card>
            </div>

            <Dialog open={row !== null} onOpenChange={close}>
                <DialogContent
                    className="flex max-h-[90vh] flex-col gap-0 p-0 sm:max-w-3xl"
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        trigger.current?.focus();
                    }}
                >
                    {row && (
                        <>
                            <DialogHeader className="border-b p-6 pb-4 text-left">
                                <DialogTitle>{row.name}</DialogTitle>
                                <DialogDescription>
                                    CNPJ {row.cnpj || 'não informado'} · {row.emission_environment === 'production' ? 'Produção' : 'Homologação'}
                                </DialogDescription>
                            </DialogHeader>

                            <Tabs value={tab} onValueChange={setTab} className="flex min-h-0 flex-1 flex-col">
                                <div className="px-6 pt-4">
                                    <TabsList className="grid h-auto w-full grid-cols-2 sm:grid-cols-4">
                                        <TabsTrigger value="general">Geral</TabsTrigger>
                                        <TabsTrigger value="models">Modelos fiscais</TabsTrigger>
                                        <TabsTrigger value="certificate">Certificado digital</TabsTrigger>
                                        <TabsTrigger value="spedy">Integração Spedy</TabsTrigger>
                                    </TabsList>
                                </div>

                                <div className="min-h-0 flex-1 overflow-y-auto px-6 py-4" aria-busy={busy !== null}>
                                    {result && (
                                        <p
                                            role={result.type === 'error' ? 'alert' : 'status'}
                                            className={`mb-4 rounded-md border p-3 text-sm ${
                                                result.type === 'error'
                                                    ? 'border-red-300 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100'
                                                    : 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100'
                                            }`}
                                        >
                                            {result.message}
                                        </p>
                                    )}

                                    <TabsContent value="general" className="mt-0">
                                        <dl className="divide-y">
                                            <Field label="Empresa">{row.name}</Field>
                                            <Field label="CNPJ">{row.cnpj || 'não informado'}</Field>
                                            <Field label="Fiscal habilitado">
                                                <div className="flex items-center gap-3">
                                                    <Switch
                                                        id="emission-enabled"
                                                        className={switchClass}
                                                        checked={row.emission_enabled}
                                                        aria-disabled={busy !== null}
                                                        onCheckedChange={(checked) => save(row, { emission_enabled: checked })}
                                                    />
                                                    <Label htmlFor="emission-enabled">{row.emission_enabled ? 'Habilitado' : 'Desabilitado'}</Label>
                                                    {busy === 'update' && <Loader2 className="size-4 animate-spin" aria-label="Salvando" />}
                                                </div>
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    Libera a conta. A empresa só emite depois do cadastro na Spedy, do certificado vigente e da
                                                    configuração de cada modelo.
                                                </p>
                                            </Field>
                                            <Field label="Situação da emissão">
                                                <ul className="space-y-1">
                                                    {models.map((model) => {
                                                        const state = modelState(row, model);
                                                        return (
                                                            <li key={model} className={state.ready ? 'text-emerald-700 dark:text-emerald-400' : ''}>
                                                                <strong>{modelNames[model]}:</strong> {state.label}
                                                            </li>
                                                        );
                                                    })}
                                                </ul>
                                                {!platformConfigured && (
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        Integração Spedy não configurada na plataforma.
                                                    </p>
                                                )}
                                            </Field>
                                            <Field label="Ambiente de emissão">
                                                <p>{row.emission_environment === 'production' ? 'Produção' : 'Homologação'}</p>
                                                <p className="text-muted-foreground text-xs">
                                                    {row.production_released_at
                                                        ? `Produção aprovada em ${formatDate(row.production_released_at)}`
                                                        : `Homologação: ${row.homologation_authorized} nota(s) autorizada(s)`}
                                                </p>
                                                <Button
                                                    size="sm"
                                                    variant={row.production_released_at ? 'outline' : 'default'}
                                                    className="mt-2"
                                                    aria-disabled={busy !== null}
                                                    ref={productionButton}
                                                    onClick={() => setConfirm(row.production_released_at ? 'revoke' : 'release')}
                                                >
                                                    {row.production_released_at ? 'Revogar produção' : 'Aprovar produção'}
                                                </Button>
                                            </Field>
                                        </dl>
                                    </TabsContent>

                                    <TabsContent value="models" className="mt-0 space-y-3">
                                        {models.map((model) => {
                                            const state = modelState(row, model);
                                            const id = `model-${model}`;
                                            return (
                                                <div key={model} className="rounded-md border p-3">
                                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                                        <div className="flex items-center gap-3">
                                                            <Switch
                                                                id={id}
                                                                className={switchClass}
                                                                checked={row.allowed[model]}
                                                                aria-disabled={busy !== null}
                                                                onCheckedChange={(checked) => save(row, { [`${model}_allowed`]: checked })}
                                                            />
                                                            <Label htmlFor={id} className="font-semibold">
                                                                {modelNames[model]} {row.allowed[model] ? 'liberada' : 'não liberada'}
                                                            </Label>
                                                        </div>
                                                        <Badge variant={state.ready ? 'default' : 'secondary'}>
                                                            {state.ready ? 'Pronta' : 'Impedida'}
                                                        </Badge>
                                                    </div>
                                                    <p
                                                        className={`mt-2 text-sm ${state.ready ? 'text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground'}`}
                                                    >
                                                        {state.label}
                                                    </p>
                                                    {!row.tenant_enabled[model] && (
                                                        <p className="text-muted-foreground mt-1 text-xs">
                                                            Modalidade desativada pelo cliente nas configurações dele.
                                                        </p>
                                                    )}
                                                </div>
                                            );
                                        })}
                                        {!row.emission_enabled && (
                                            <p className="text-muted-foreground text-xs">
                                                Habilite o fiscal na aba Geral para que os modelos liberados possam emitir.
                                            </p>
                                        )}
                                    </TabsContent>

                                    <TabsContent value="certificate" className="mt-0">
                                        <dl className="divide-y">
                                            <Field label="Situação">
                                                <Badge variant={cert?.valid ? 'default' : 'secondary'}>{cert?.valid ? 'Vigente' : 'Pendente'}</Badge>
                                                <span className="ml-2">{cert?.label}</span>
                                            </Field>
                                            <Field label="Titular">{row.certificate_subject || 'não informado'}</Field>
                                            <Field label="Validade">
                                                {row.certificate_expires_at ? formatDate(row.certificate_expires_at) : '-'}
                                            </Field>
                                            {certificateBlockers.length > 0 && (
                                                <Field label="Bloqueios">
                                                    <ul className="list-disc space-y-1 pl-4">
                                                        {certificateBlockers.map((blocker) => (
                                                            <li key={blocker}>{blocker}</li>
                                                        ))}
                                                    </ul>
                                                </Field>
                                            )}
                                        </dl>
                                        <p className="bg-muted mt-4 rounded-md p-3 text-sm">
                                            O certificado A1 é enviado pelo titular da empresa no VetorOS, em Configurações → Fiscal. Depois do envio,
                                            use <strong>Consultar cadastro e certificado</strong> na aba Integração Spedy para confirmar titular e
                                            validade.
                                        </p>
                                    </TabsContent>

                                    <TabsContent value="spedy" className="mt-0">
                                        <dl className="divide-y">
                                            <Field label="Cadastro">
                                                <RegistrationBadge row={row} />
                                                <span className="ml-2">{registration(row).detail}</span>
                                            </Field>
                                            <Field label="Identificador remoto">
                                                <span className="font-mono text-xs break-all">{row.spedy_company_id || '-'}</span>
                                            </Field>
                                            <Field label="Ambiente do vínculo">
                                                {row.spedy_environment ? (environmentLabels[row.spedy_environment] ?? row.spedy_environment) : '-'}
                                                {row.environment_mismatch && (
                                                    <p className="text-destructive text-xs">
                                                        Vínculo de outro ambiente: não é usado na plataforma atual.
                                                    </p>
                                                )}
                                            </Field>
                                            <Field label="Cadastrada em">{formatDate(row.registered_at)}</Field>
                                            <Field label="Última sincronização">{formatDate(row.last_synced_at)}</Field>
                                            {row.registration_error && (
                                                <Field label="Erro da integração">
                                                    <span className="text-destructive">{row.registration_error}</span>
                                                </Field>
                                            )}
                                        </dl>
                                        <div className="mt-4 flex flex-col gap-2 sm:flex-row">
                                            <Button
                                                disabled={!row.emission_enabled || !platformConfigured}
                                                aria-disabled={busy !== null}
                                                onClick={() => register(row)}
                                            >
                                                {busy === 'register' && <Loader2 className="size-4 animate-spin" />}
                                                {row.registered_on_spedy ? 'Sincronizar' : 'Cadastrar na Spedy'}
                                            </Button>
                                            <Button
                                                variant="outline"
                                                disabled={!row.registered_on_spedy}
                                                aria-disabled={busy !== null}
                                                onClick={() => check(row)}
                                            >
                                                {busy === 'check' && <Loader2 className="size-4 animate-spin" />}
                                                Consultar cadastro e certificado
                                            </Button>
                                        </div>
                                        {!row.emission_enabled && (
                                            <p className="text-muted-foreground mt-2 text-xs">
                                                Habilite o fiscal na aba Geral antes de cadastrar na Spedy.
                                            </p>
                                        )}
                                    </TabsContent>
                                </div>
                            </Tabs>
                        </>
                    )}

                    <AlertDialog open={confirm !== null && row !== null} onOpenChange={(value) => !value && !busy && setConfirm(null)}>
                        <AlertDialogContent
                            onCloseAutoFocus={(event) => {
                                event.preventDefault();
                                productionButton.current?.focus();
                            }}
                        >
                            {row && (
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        confirmProduction();
                                    }}
                                >
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>
                                            {confirm === 'release' ? 'Aprovar emissão em produção?' : 'Revogar emissão em produção?'}
                                        </AlertDialogTitle>
                                        <AlertDialogDescription>
                                            {confirm === 'release'
                                                ? `${row.name} passará a emitir notas com validade fiscal. Notas autorizadas em homologação: ${row.homologation_authorized}.`
                                                : `${row.name} volta para homologação e deixa de emitir em produção.`}
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    {confirm === 'release' && (
                                        <div className="my-4 space-y-1">
                                            <Label htmlFor="production-password">Sua senha</Label>
                                            <Input
                                                id="production-password"
                                                type="password"
                                                autoComplete="current-password"
                                                value={password}
                                                onChange={(e) => setPassword(e.target.value)}
                                                autoFocus
                                                required
                                            />
                                            {errors.password && <p className="text-destructive text-sm">{errors.password}</p>}
                                        </div>
                                    )}
                                    <AlertDialogFooter className="mt-4">
                                        <AlertDialogCancel type="button" disabled={busy !== null}>
                                            Cancelar
                                        </AlertDialogCancel>
                                        <AlertDialogAction
                                            type="submit"
                                            disabled={busy !== null || (confirm === 'release' && !password)}
                                            onClick={(e) => {
                                                // O envio fica com o formulário; o diálogo só fecha depois do resultado.
                                                e.preventDefault();
                                                confirmProduction();
                                            }}
                                        >
                                            {busy === 'update' && <Loader2 className="size-4 animate-spin" />}
                                            {confirm === 'release' ? 'Aprovar produção' : 'Revogar produção'}
                                        </AlertDialogAction>
                                    </AlertDialogFooter>
                                </form>
                            )}
                        </AlertDialogContent>
                    </AlertDialog>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
