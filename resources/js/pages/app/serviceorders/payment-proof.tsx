import { Button } from '@/components/ui/button';
import { formatBRL } from '@/lib/money';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CreditCard, Printer, ReceiptText } from 'lucide-react';

function formatDateTime(value?: string) {
    if (!value) return '-';
    return new Date(value).toLocaleString('pt-BR');
}

export default function PaymentProof({ order, company, backUrl }: any) {
    const totalPaid = (order?.order_payments ?? []).reduce((sum: number, payment: any) => sum + Number(payment?.amount ?? 0), 0);
    const totalOrder = Number(order?.service_cost ?? 0);
    const remaining = Math.max(0, totalOrder - totalPaid);

    return (
        <>
            <Head title={`Comprovante financeiro #${order?.order_number}`} />

            <div className="mx-auto max-w-4xl p-4 md:p-8">
                <div className="mb-4 flex items-center justify-between gap-3 print:hidden">
                    <Button asChild variant="outline" className="gap-2">
                        <Link href={backUrl}>
                            <ArrowLeft className="h-4 w-4" />
                            Voltar para a ordem
                        </Link>
                    </Button>

                    <Button onClick={() => window.print()} className="gap-2">
                        <Printer className="h-4 w-4" />
                        Imprimir comprovante
                    </Button>
                </div>

                <div className="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-sm print:rounded-none print:border-none print:shadow-none">
                    <div className="border-b border-slate-200 bg-slate-950 px-6 py-8 text-white">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="space-y-2">
                                <p className="text-xs tracking-[0.24em] text-slate-300 uppercase">Comprovante financeiro</p>
                                <h1 className="text-3xl font-semibold tracking-tight">Ordem de serviço #{order?.order_number}</h1>
                                <p className="max-w-2xl text-sm text-slate-300">Resumo dos pagamentos registrados para este atendimento.</p>
                            </div>

                            <div className="rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                                <p className="text-xs tracking-[0.18em] text-slate-300 uppercase">Cliente</p>
                                <p className="mt-2 font-medium text-white">{order?.customer?.name || '-'}</p>
                            </div>
                        </div>
                    </div>

                    <div className="space-y-6 p-6">
                        <section className="grid gap-4 md:grid-cols-3">
                            <div className="rounded-2xl border border-slate-200 p-4">
                                <p className="text-sm text-slate-500">Total do atendimento</p>
                                <p className="mt-2 text-xl font-semibold text-slate-900">{formatBRL(totalOrder)}</p>
                            </div>
                            <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                                <p className="text-sm text-slate-500">Valor pago</p>
                                <p className="mt-2 text-xl font-semibold text-emerald-700">{formatBRL(totalPaid)}</p>
                            </div>
                            <div className="rounded-2xl border border-slate-200 p-4">
                                <p className="text-sm text-slate-500">Saldo remanescente</p>
                                <p className={`mt-2 text-xl font-semibold ${remaining > 0.009 ? 'text-rose-600' : 'text-emerald-700'}`}>
                                    {formatBRL(remaining)}
                                </p>
                            </div>
                        </section>

                        <section className="rounded-[24px] border border-slate-200 bg-slate-50 p-5">
                            <div className="mb-4 flex items-center gap-3">
                                <ReceiptText className="h-5 w-5 text-slate-500" />
                                <h2 className="text-lg font-semibold text-slate-900">Pagamentos registrados</h2>
                            </div>

                            <div className="space-y-3">
                                {(order?.order_payments ?? []).map((payment: any) => (
                                    <div key={payment.id} className="rounded-2xl border border-slate-200 bg-white p-4">
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                            <div className="space-y-1">
                                                <p className="font-medium text-slate-900">Pagamento registrado</p>
                                                <p className="text-sm text-slate-500">
                                                    {payment?.payment_method || 'Pagamento'} • {formatDateTime(payment?.paid_at)}
                                                </p>
                                                {payment?.notes ? <p className="text-sm text-slate-600">{payment.notes}</p> : null}
                                            </div>

                                            <div className="inline-flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-emerald-700">
                                                <CreditCard className="h-4 w-4" />
                                                <span className="font-semibold">{formatBRL(payment?.amount ?? 0)}</span>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>

                        <section className="rounded-[24px] border border-slate-200 bg-white p-5">
                            <h2 className="text-lg font-semibold text-slate-900">Dados do atendimento</h2>
                            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <p className="text-sm text-slate-500">Equipamento</p>
                                    <p className="font-medium text-slate-900">{order?.equipment?.equipment || '-'}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-slate-500">Modelo</p>
                                    <p className="font-medium text-slate-900">{order?.model || '-'}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-slate-500">Empresa</p>
                                    <p className="font-medium text-slate-900">{company?.companyname || '-'}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-slate-500">Emitido em</p>
                                    <p className="font-medium text-slate-900">{formatDateTime(new Date().toISOString())}</p>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}
