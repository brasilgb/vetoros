import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, Printer, ReceiptText } from 'lucide-react';

function formatDateTime(value?: string) {
    if (!value) return '-';
    return new Date(value).toLocaleString('pt-BR');
}

export default function FiscalProof({ order, company, backUrl }: any) {
    return (
        <>
            <Head title={`Comprovante fiscal #${order?.order_number}`} />

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
                                <p className="text-xs tracking-[0.24em] text-slate-300 uppercase">Comprovante fiscal</p>
                                <h1 className="text-3xl font-semibold tracking-tight">Ordem de serviço #{order?.order_number}</h1>
                                <p className="max-w-2xl text-sm text-slate-300">Dados do documento fiscal registrado para este atendimento.</p>
                            </div>

                            <div className="rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                                <p className="text-xs tracking-[0.18em] text-slate-300 uppercase">Cliente</p>
                                <p className="mt-2 font-medium text-white">{order?.customer?.name || '-'}</p>
                            </div>
                        </div>
                    </div>

                    <div className="space-y-6 p-6">
                        <section className="rounded-[24px] border border-slate-200 bg-slate-50 p-5">
                            <div className="mb-4 flex items-center gap-3">
                                <ReceiptText className="h-5 w-5 text-slate-500" />
                                <h2 className="text-lg font-semibold text-slate-900">Documento fiscal</h2>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <p className="text-sm text-slate-500">Número do documento</p>
                                    <p className="font-medium text-slate-900">{order?.fiscal_document_number || '-'}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-slate-500">Emitido em</p>
                                    <p className="font-medium text-slate-900">{formatDateTime(order?.fiscal_issued_at)}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-slate-500">Empresa</p>
                                    <p className="font-medium text-slate-900">{company?.companyname || '-'}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-slate-500">Equipamento</p>
                                    <p className="font-medium text-slate-900">{order?.equipment?.equipment || '-'}</p>
                                </div>
                            </div>

                            {order?.fiscal_notes && (
                                <div className="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
                                    <p className="text-sm text-slate-500">Observações fiscais</p>
                                    <p className="mt-2 text-sm leading-6 text-slate-700">{order.fiscal_notes}</p>
                                </div>
                            )}

                            {order?.fiscal_document_url && (
                                <div className="mt-4">
                                    <a
                                        href={order.fiscal_document_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:bg-slate-100 print:hidden"
                                    >
                                        Consultar documento fiscal
                                        <ExternalLink className="h-4 w-4" />
                                    </a>
                                </div>
                            )}
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}
