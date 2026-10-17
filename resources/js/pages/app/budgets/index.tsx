import ActionDelete from '@/components/action-delete';
import AppPagination, { PaginationSummary } from '@/components/app-pagination';
import { Icon } from '@/components/icon';
import InputSearch from '@/components/inputSearch';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatBRL } from '@/lib/money';
import { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Edit, Plus, ScrollText } from 'lucide-react';
import moment from 'moment';
import { PrintBudget } from './print-buget';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Orçamentos',
        href: '',
    },
];

export default function CheckList({ budgets, company, search }: any) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Orçamentos" />
            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={ScrollText} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Orçamentos</h2>
                </div>
                <p className="text-muted-foreground text-sm">Consulte e acompanhe os orçamentos de serviços cadastrados no sistema.</p>
            </div>

            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="w-full min-w-0 lg:max-w-[420px] lg:flex-1">
                    <InputSearch placeholder="Pesquisar número, equipamento, modelo ou serviço" url="app.budgets.index" />
                </div>
                <div className="bg-accent text-accent-foreground min-w-0 rounded-md p-2 text-center text-xs font-medium lg:flex-1">
                    Os dados da empresa devem estar preenchidos para exibir corretamente o orçamento.
                </div>
                <div className="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:shrink-0 lg:justify-end">
                    <Button variant={'default'} asChild className="w-full whitespace-nowrap sm:w-auto">
                        <Link href={route('app.budgets.create')}>
                            <Plus className="h-4 w-4" />
                            <span>Novo orçamento</span>
                        </Link>
                    </Button>
                </div>
            </div>
            <div className="p-4">
                <PaginationSummary data={budgets} />
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-[100px]">#</TableHead>
                                <TableHead>Equipamento</TableHead>
                                <TableHead>Serviço</TableHead>
                                <TableHead>Condições</TableHead>
                                <TableHead>Valor</TableHead>
                                <TableHead>Cadastro</TableHead>
                                <TableHead className="min-w-[140px]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {budgets?.data.length ? (
                                budgets?.data?.map((budget: any) => {
                                    const ageDays = Math.max(0, moment().diff(moment(budget.created_at), 'days'));
                                    const expiresAt = budget.validity
                                        ? moment(budget.created_at).add(Number(budget.validity), 'days').endOf('day')
                                        : null;
                                    const isExpired = Boolean(expiresAt?.isBefore(moment()));

                                    return (
                                        <TableRow key={budget.id} className={isExpired ? 'bg-amber-50/60 dark:bg-amber-950/15' : ''}>
                                            <TableCell>{budget.budget_number}</TableCell>
                                            <TableCell>
                                                <div className="space-y-1">
                                                    <div className="font-medium">{budget.equipment.equipment}</div>
                                                    <div className="text-muted-foreground text-xs">{budget.model || 'Modelo não informado'}</div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="space-y-1">
                                                    <div className="font-medium">{budget.service}</div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="text-sm">
                                                    <span className="text-muted-foreground">Tempo:</span> {budget.estimated_time || '-'} ·{' '}
                                                    <span className="text-muted-foreground">Garantia:</span> {budget.warranty || '-'} ·{' '}
                                                    <span className="text-muted-foreground">Validade:</span>{' '}
                                                    {budget.validity ? `${budget.validity} dias` : '-'}
                                                </div>
                                            </TableCell>
                                            <TableCell>{formatBRL(budget.total_value)}</TableCell>
                                            <TableCell>
                                                <div className="space-y-1">
                                                    <div>{moment(budget.created_at).format('DD/MM/YYYY')}</div>
                                                    <div className="text-muted-foreground text-xs">
                                                        {ageDays === 0 ? 'Criado hoje' : `Criado há ${ageDays} ${ageDays === 1 ? 'dia' : 'dias'}`}
                                                    </div>
                                                    {isExpired && (
                                                        <Badge variant="secondary" className="bg-amber-100 text-amber-900">
                                                            Validade vencida
                                                        </Badge>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="min-w-[140px]">
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    <PrintBudget company={company} budget={budget} />
                                                    <Button
                                                        asChild
                                                        size="icon"
                                                        className="bg-orange-500 text-white hover:bg-orange-600"
                                                        title="Editar orçamento"
                                                    >
                                                        <Link
                                                            href={route('app.budgets.edit', budget.id)}
                                                            data={{ page: budgets.current_page, search: search }}
                                                            aria-label={`Editar orçamento ${budget.budget_number}`}
                                                        >
                                                            <Edit className="h-4 w-4" />
                                                        </Link>
                                                    </Button>

                                                    <ActionDelete title={'este orçamento'} url={'app.budgets.destroy'} param={budget.id} />
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={7} className="flex h-16 w-full items-center justify-center">
                                        Não há dados a serem mostrados no momento.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={7}>
                                    <AppPagination data={budgets} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
