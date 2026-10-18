import AlertSuccess from '@/components/app-alert-success';
import AppPagination from '@/components/app-pagination';
import { Icon } from '@/components/icon';
import InputSearch from '@/components/inputSearch';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/admin/admin-layout';
import type { BreadcrumbItem, PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { BookOpenText, Loader2Icon, Upload } from 'lucide-react';
import moment from 'moment';
import { useState } from 'react';

interface HelpTopic {
    id: number;
    slug: string;
    title: string;
    category: string | null;
    position: number;
    updated_at: string;
}

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    links: Array<{ url: string | null; label: string; active?: boolean }>;
    first_page_url: string | null;
    prev_page_url: string | null;
    next_page_url: string | null;
    last_page_url: string | null;
}

interface HelpTopicsIndexProps {
    topics: PaginatedData<HelpTopic>;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
    },
    {
        title: 'Manual de ajuda',
        href: '#',
    },
];

export default function HelpTopicsIndex({ topics }: HelpTopicsIndexProps) {
    const { flash } = usePage<PageProps<{ flash?: { import_success?: string; import_error?: string } }>>().props;
    const [importing, setImporting] = useState(false);

    const handleImport = () => {
        setImporting(true);
        router.post(
            route('admin.help-topics.import'),
            {},
            {
                preserveScroll: true,
                onFinish: () => setImporting(false),
            },
        );
    };

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Manual de ajuda" />
            {flash?.import_success && <AlertSuccess message={flash.import_success} />}
            {flash?.import_error && (
                <Alert variant="destructive" className="mx-4 mt-4">
                    <AlertDescription>{flash.import_error}</AlertDescription>
                </Alert>
            )}
            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={BookOpenText} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Manual de ajuda</h2>
                </div>
                <p className="text-muted-foreground text-sm">
                    Tópicos exibidos na busca de ajuda do app. Após atualizar o manual HTML, rode{' '}
                    <code className="bg-muted rounded px-1 py-0.5 text-xs">php artisan vetoros:export-manual</code> e clique em Importar manual.
                </p>
            </div>
            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="w-full min-w-0 lg:max-w-[360px] lg:flex-1">
                    <InputSearch placeholder="Buscar tópico" url="admin.help-topics.index" />
                </div>
                <div className="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:shrink-0 lg:justify-end">
                    <Button onClick={handleImport} disabled={importing} className="w-full gap-2 whitespace-nowrap sm:w-auto">
                        {importing ? <Loader2Icon className="h-4 w-4 animate-spin" /> : <Upload className="h-4 w-4" />}
                        Importar manual
                    </Button>
                </div>
            </div>

            <div className="p-4">
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead>
                                <TableHead>Título</TableHead>
                                <TableHead>Slug</TableHead>
                                <TableHead>Categoria</TableHead>
                                <TableHead>Atualizado em</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {topics.data.length > 0 ? (
                                topics.data.map((topic) => (
                                    <TableRow key={topic.id}>
                                        <TableCell>{topic.id}</TableCell>
                                        <TableCell>{topic.title}</TableCell>
                                        <TableCell className="text-muted-foreground">{topic.slug}</TableCell>
                                        <TableCell>{topic.category ?? '—'}</TableCell>
                                        <TableCell>{moment(topic.updated_at).format('DD/MM/YYYY HH:mm')}</TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={5} className="flex h-16 w-full items-center justify-center">
                                        Nenhum tópico importado ainda.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={5}>
                                    <AppPagination data={topics} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </AdminLayout>
    );
}
