import { SCHEDULE_STATUS_LABELS } from '@/lib/reports/report-labels';
import moment from 'moment';
import ReportTable from './report-table';
import SummaryCard from './summary-card';

export default function SchedulesReportView({ data }: { data: ReportRow[]; reportMeta: ReportMeta }) {
    const openedCount = data.filter((schedule) => Number(schedule.status) === 1).length;
    const inServiceCount = data.filter((schedule) => Number(schedule.status) === 2).length;
    const closedCount = data.filter((schedule) => Number(schedule.status) === 3).length;

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 print:grid-cols-4">
                <SummaryCard label="Visitas no período" value={data.length} />
                <SummaryCard label="Abertas" value={openedCount} />
                <SummaryCard label="Em atendimento" value={inServiceCount} />
                <SummaryCard label="Fechadas" value={closedCount} />
            </div>

            <ReportTable
                rows={data}
                emptyMessage="Nenhuma visita no período."
                columns={[
                    { header: '#', align: 'center', render: (schedule) => schedule.schedules_number },
                    { header: 'Cliente', render: (schedule) => schedule.customer?.name || '—' },
                    { header: 'Visita', nowrap: true, render: (schedule) => moment(schedule.schedules).format('DD/MM/YYYY HH:mm') },
                    { header: 'Serviço', render: (schedule) => schedule.service || '—' },
                    { header: 'Status', render: (schedule) => SCHEDULE_STATUS_LABELS[Number(schedule.status)] || '—' },
                    { header: 'Técnico', render: (schedule) => schedule.user?.name || '—' },
                    { header: 'Solicitação', align: 'right', nowrap: true, render: (schedule) => moment(schedule.created_at).format('DD/MM/YYYY') },
                ]}
            />
        </div>
    );
}
