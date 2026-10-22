import { ReactNode } from 'react';

export type ReportColumn<T> = {
    header: string;
    align?: 'left' | 'center' | 'right';
    nowrap?: boolean;
    render: (row: T) => ReactNode;
};

const ALIGN_CLASS = { left: '', center: 'text-center', right: 'text-right' };

export default function ReportTable<T extends { id: number | string }>({
    rows,
    columns,
    emptyMessage,
}: {
    rows: T[];
    columns: ReportColumn<T>[];
    emptyMessage: string;
}) {
    return (
        <div className="overflow-x-auto rounded-md border border-slate-200 print:overflow-visible">
            <table className="w-full text-left text-xs">
                <thead className="bg-slate-100 text-slate-700">
                    <tr>
                        {columns.map((column) => (
                            <th key={column.header} className={`px-2 py-1.5 ${ALIGN_CLASS[column.align ?? 'left']}`}>
                                {column.header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={columns.length} className="px-2 py-6 text-center text-slate-500">
                                {emptyMessage}
                            </td>
                        </tr>
                    )}
                    {rows.map((row) => (
                        <tr key={row.id} className="border-t border-slate-100 break-inside-avoid">
                            {columns.map((column) => (
                                <td
                                    key={column.header}
                                    className={`px-2 py-1 ${ALIGN_CLASS[column.align ?? 'left']} ${column.nowrap ? 'whitespace-nowrap' : ''}`}
                                >
                                    {column.render(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
