import { Button } from '@/components/ui/button';
import { formatBRL } from '@/lib/money';
import { Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import moment from 'moment';

const normalizePlaceholderKey = (key: string) =>
    key
        .trim()
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, '_')
        .replace(/-/g, '_');

function contractVigencyLabel(contract: any) {
    if (!contract?.duration_months) return 'Indeterminada';

    const months = Number(contract.duration_months);
    return `${months} ${months === 1 ? 'mês' : 'meses'}`;
}

function renderContractTemplate(template: string, contract: any, company: any) {
    const values = {
        empresa: company?.companyname ?? '',
        cnpj_empresa: company?.cnpj ?? '',
        cliente: contract?.customer?.name ?? '',
        cpf_cnpj: contract?.customer?.cpfcnpj ?? '',
        descricao: contract?.description ?? '',
        valor_mensalidade: formatBRL(contract?.monthly_amount),
        dia_cobranca: String(contract?.billing_day ?? ''),
        data_inicio: contract?.start_date ? moment(contract.start_date).format('DD/MM/YYYY') : '',
        vigencia: contractVigencyLabel(contract),
    };

    return (template || '').replace(/\{\{\s*([^}]+?)\s*\}\}/g, (_, key) => {
        const normalizedKey = normalizePlaceholderKey(String(key));

        if (normalizedKey in values) {
            return values[normalizedKey as keyof typeof values];
        }

        return '';
    });
}

export default function PrintMaintenanceContract({ contract, company, template }: { contract: any; company: any; template?: string }) {
    const handlePrint = () => {
        window.print();
    };

    const contractText = renderContractTemplate(template ?? '', contract, company);

    return (
        <div className="relative mx-auto max-w-4xl">
            <div className="absolute mb-4 flex w-full items-center justify-between print:hidden">
                <Button asChild className="gap-2">
                    <Link href={route('app.maintenance-contracts.index')}>
                        <ArrowLeft className="h-4 w-4" />
                    </Link>
                </Button>
                <Button onClick={handlePrint} className="gap-2">
                    <Printer className="h-4 w-4" />
                    Imprimir
                </Button>
            </div>

            <div className="min-h-screen bg-white p-8 text-[12px] text-gray-800 shadow-lg print:shadow-none">
                <div className="mb-6 flex items-start justify-between gap-4 border-b border-gray-300 pb-4">
                    <div className="flex items-start gap-3">
                        <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-md border border-gray-200 bg-white p-1">
                            <img
                                src={`${company?.logo ? `/storage/logos/${company.logo}` : '/images/default.png'}`}
                                alt={company?.companyname ? `Logo ${company.companyname}` : 'Logo da empresa'}
                                className="h-full w-full object-contain"
                                onError={(event) => {
                                    event.currentTarget.src = '/images/default.png';
                                }}
                            />
                        </div>
                        <div className="space-y-0.5">
                            <h1 className="text-sm font-bold text-gray-950">{company?.companyname ?? '-'}</h1>
                            <p>CNPJ: {company?.cnpj ?? '-'}</p>
                            <p>
                                {company?.street ?? '-'}, {company?.number ?? '-'} - {company?.district ?? '-'}
                            </p>
                            <p>
                                {company?.city ?? '-'} {company?.state ? `- ${company.state}` : ''} | {company?.telephone ?? '-'}
                            </p>
                        </div>
                    </div>
                    <div className="min-w-40 rounded-md border border-gray-300 p-2 text-right">
                        <p className="text-[9px] font-semibold tracking-wide text-gray-500 uppercase">Contrato de Manutenção</p>
                        <p className="text-base font-bold text-gray-950">#{contract?.contract_number ?? '-'}</p>
                        <p className="text-[10px] text-gray-500">{new Date().toLocaleDateString('pt-BR')}</p>
                    </div>
                </div>

                <h2 className="mb-4 text-center text-sm font-bold tracking-wide uppercase">Contrato de Prestação de Serviços de Manutenção</h2>

                <div className="mb-4 rounded-md border border-gray-300">
                    <div className="border-b border-gray-300 bg-gray-100 px-3 py-1.5 text-[11px] font-bold text-gray-950 uppercase">
                        Dados do contratante
                    </div>
                    <div className="grid gap-x-6 gap-y-1 p-3 md:grid-cols-2">
                        <p>
                            <span className="font-semibold">Cliente:</span> {contract?.customer?.name ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">CPF/CNPJ:</span> {contract?.customer?.cpfcnpj ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Telefone:</span> {contract?.customer?.phone ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Endereço:</span> {contract?.customer?.street ?? '-'}, {contract?.customer?.number ?? '-'}{' '}
                            - {contract?.customer?.district ?? '-'}
                        </p>
                    </div>
                </div>

                <div className="mb-4 rounded-md border border-gray-300">
                    <div className="border-b border-gray-300 bg-gray-100 px-3 py-1.5 text-[11px] font-bold text-gray-950 uppercase">
                        Condições do contrato
                    </div>
                    <div className="grid gap-x-6 gap-y-1 p-3 md:grid-cols-2">
                        <p>
                            <span className="font-semibold">Descrição:</span> {contract?.description ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Mensalidade:</span> {formatBRL(contract?.monthly_amount)}
                        </p>
                        <p>
                            <span className="font-semibold">Dia de cobrança:</span> {contract?.billing_day ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Início:</span>{' '}
                            {contract?.start_date ? moment(contract.start_date).format('DD/MM/YYYY') : '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Vigência:</span> {contractVigencyLabel(contract)}
                        </p>
                        <p>
                            <span className="font-semibold">Técnico responsável:</span> {contract?.preferred_technician?.name ?? 'A definir'}
                        </p>
                    </div>
                </div>

                <div className="mb-6 rounded-md border border-gray-300">
                    <div className="border-b border-gray-300 bg-gray-100 px-3 py-1.5 text-[11px] font-bold text-gray-950 uppercase">Cláusulas</div>
                    <p className="min-h-20 p-3 text-[11px] leading-relaxed whitespace-pre-wrap">{contractText || '-'}</p>
                </div>

                <div className="mt-16 grid grid-cols-2 items-end gap-8">
                    <div className="text-center">
                        <div className="mb-1 border-t border-black"></div>
                        <p>{company?.companyname ?? 'Contratada'}</p>
                    </div>
                    <div className="text-center">
                        <div className="mb-1 border-t border-black"></div>
                        <p>{contract?.customer?.name ?? 'Contratante'}</p>
                    </div>
                </div>
            </div>
        </div>
    );
}
