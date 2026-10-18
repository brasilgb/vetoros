import { Document, Image, Page, StyleSheet, Text, View } from '@react-pdf/renderer';
import moment from 'moment';

moment.locale('pt-br');

type RankingItem = {
    label: string;
    total: number;
    prefix: string;
};

type LowFeedbackOrder = {
    id: string | number;
    order_number: string | number;
    customer: string;
    rating: number;
    comment?: string;
    recovery_status?: string;
    recovery_assigned_to?: string;
    submitted_at?: string;
};

const styles = StyleSheet.create({
    page: { padding: 18, fontSize: 9, fontFamily: 'Helvetica', backgroundColor: '#FAFAFA' },
    logoPlaceholder: { paddingVertical: 2, width: 38, height: 38, justifyContent: 'center', alignItems: 'center', minWidth: '100%' },
    title: { fontSize: 15, textAlign: 'center', marginBottom: 4, color: '#333' },
    subtitle: { fontSize: 10, textAlign: 'center', marginBottom: 8, color: '#555' },
    headerInfo: { fontSize: 9, textAlign: 'center', marginBottom: 10, color: '#666' },
    section: { marginBottom: 12 },
    sectionTitle: { fontSize: 11, marginBottom: 6, fontWeight: 'bold', color: '#111827' },
    summaryGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
    summaryCard: { width: '31%', border: '1px solid #d9d9d9', borderRadius: 4, paddingVertical: 6, paddingHorizontal: 8, backgroundColor: '#fff' },
    summaryLabel: { fontSize: 8, color: '#666', marginBottom: 2, textTransform: 'uppercase' },
    summaryValue: { fontSize: 9, color: '#111', fontWeight: 'bold' },
    tableContainer: { border: '1px solid #e5e5e5', borderRadius: 4, overflow: 'hidden', backgroundColor: '#fff' },
    tableHeader: { flexDirection: 'row', backgroundColor: '#efefef', borderBottom: '1px solid #cfcfcf', paddingVertical: 6, paddingHorizontal: 8 },
    tableRow: { flexDirection: 'row', borderBottom: '0.5px solid #e8e8e8', paddingVertical: 5, paddingHorizontal: 8 },
    colLabel: { width: '76%' },
    colTotal: { width: '24%', textAlign: 'right' },
    colOrder: { width: '16%' },
    colCustomer: { width: '30%' },
    colRating: { width: '10%', textAlign: 'center' },
    colStatus: { width: '18%' },
    colDate: { width: '26%', textAlign: 'right' },
    comment: { fontSize: 8, color: '#4b5563', marginTop: 2 },
    emptyState: { paddingVertical: 10, textAlign: 'center', color: '#666' },
});

function recoveryStatusLabel(status?: string) {
    if (status === 'resolved') return 'Resolvido';
    if (status === 'in_progress') return 'Em tratativa';
    return 'Pendente';
}

export default function QualityReportPDF({ reportMeta, dateRange, company }: any) {
    const period =
        dateRange?.from && dateRange?.to
            ? `${moment(dateRange.from).format('DD/MM/YYYY')} - ${moment(dateRange.to).format('DD/MM/YYYY')}`
            : 'Período não informado';

    const summary = reportMeta?.summary ?? {};
    const topEquipments = (Array.isArray(reportMeta?.top_equipments) ? reportMeta.top_equipments : []) as Array<{ label: string; total: number }>;
    const topDefects = (Array.isArray(reportMeta?.top_defects) ? reportMeta.top_defects : []) as Array<{ label: string; total: number }>;
    const topTechnicians = (Array.isArray(reportMeta?.top_technicians) ? reportMeta.top_technicians : []) as Array<{ label: string; total: number }>;
    const lowFeedbackOrders = (Array.isArray(reportMeta?.low_feedback_orders) ? reportMeta.low_feedback_orders : []) as LowFeedbackOrder[];
    const transparentRowStyle = { borderBottom: '0px solid transparent' } as const;
    const rankingItems: RankingItem[] = [
        ...topEquipments.map((item) => ({ ...item, prefix: 'Equipamento' })),
        ...topDefects.map((item) => ({ ...item, prefix: 'Defeito' })),
        ...topTechnicians.map((item) => ({ ...item, prefix: 'Técnico' })),
    ].slice(0, 12);

    return (
        <Document>
            <Page size="A4" style={styles.page}>
                <View style={styles.logoPlaceholder}>
                    <Image source={`${company?.logo ? `/storage/logos/${company?.logo}` : '/images/default.png'}`} />
                </View>
                <Text style={styles.title}>{company?.companyname || 'Empresa'}</Text>
                <Text style={styles.subtitle}>Relatório de Garantia e Avaliações</Text>
                <Text style={styles.headerInfo}>
                    Período: {period}
                    {'\n'}
                    Emitido em: {moment().format('DD/MM/YYYY HH:mm')}
                </Text>

                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Resumo</Text>
                    <View style={styles.summaryGrid}>
                        <View style={styles.summaryCard}>
                            <Text style={styles.summaryLabel}>Ordens no período</Text>
                            <Text style={styles.summaryValue}>{Number(summary.total_orders || 0)}</Text>
                        </View>
                        <View style={styles.summaryCard}>
                            <Text style={styles.summaryLabel}>Retornos em garantia</Text>
                            <Text style={styles.summaryValue}>{Number(summary.warranty_returns || 0)}</Text>
                        </View>
                        <View style={styles.summaryCard}>
                            <Text style={styles.summaryLabel}>Taxa de garantia</Text>
                            <Text style={styles.summaryValue}>
                                {Number(summary.warranty_return_rate || 0).toFixed(1)}% • {summary.severity || 'Saudável'}
                            </Text>
                        </View>
                        <View style={styles.summaryCard}>
                            <Text style={styles.summaryLabel}>Avaliações recebidas</Text>
                            <Text style={styles.summaryValue}>{Number(summary.feedback_responses || 0)}</Text>
                        </View>
                        <View style={styles.summaryCard}>
                            <Text style={styles.summaryLabel}>Nota média</Text>
                            <Text style={styles.summaryValue}>{Number(summary.feedback_average_rating || 0).toFixed(1)}</Text>
                        </View>
                        <View style={styles.summaryCard}>
                            <Text style={styles.summaryLabel}>Resposta às avaliações</Text>
                            <Text style={styles.summaryValue}>{Number(summary.feedback_response_rate || 0).toFixed(1)}%</Text>
                        </View>
                        <View style={styles.summaryCard}>
                            <Text style={styles.summaryLabel}>Notas baixas</Text>
                            <Text style={styles.summaryValue}>{Number(summary.low_feedbacks || 0)}</Text>
                        </View>
                        <View style={styles.summaryCard}>
                            <Text style={styles.summaryLabel}>Tratativas pendentes</Text>
                            <Text style={styles.summaryValue}>{Number(summary.recovery_pending || 0)}</Text>
                        </View>
                        <View style={styles.summaryCard}>
                            <Text style={styles.summaryLabel}>Em tratativa / resolvidas</Text>
                            <Text style={styles.summaryValue}>
                                {Number(summary.recovery_in_progress || 0)} / {Number(summary.recovery_resolved || 0)}
                            </Text>
                        </View>
                    </View>
                </View>

                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Pontos de atenção</Text>
                    <View style={styles.tableContainer}>
                        <View style={styles.tableHeader}>
                            <Text style={styles.colLabel}>Ranking</Text>
                            <Text style={styles.colTotal}>Total</Text>
                        </View>
                        {rankingItems.map((item, index) => (
                            <View
                                key={`${item.prefix}-${item.label}`}
                                style={[styles.tableRow, ...(index === rankingItems.length - 1 ? [transparentRowStyle] : [])]}
                            >
                                <Text style={styles.colLabel}>
                                    {item.prefix}: {item.label}
                                </Text>
                                <Text style={styles.colTotal}>{item.total}</Text>
                            </View>
                        ))}
                    </View>
                </View>

                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Avaliações críticas</Text>
                    <View style={styles.tableContainer}>
                        <View style={styles.tableHeader}>
                            <Text style={styles.colOrder}>OS</Text>
                            <Text style={styles.colCustomer}>Cliente</Text>
                            <Text style={styles.colRating}>Nota</Text>
                            <Text style={styles.colStatus}>Tratativa</Text>
                            <Text style={styles.colDate}>Recebida em</Text>
                        </View>
                        {lowFeedbackOrders.length ? (
                            lowFeedbackOrders.map((item, index) => (
                                <View
                                    key={item.id}
                                    style={[styles.tableRow, ...(index === lowFeedbackOrders.length - 1 ? [transparentRowStyle] : [])]}
                                >
                                    <Text style={styles.colOrder}>#{item.order_number}</Text>
                                    <View style={styles.colCustomer}>
                                        <Text>{item.customer}</Text>
                                        {item.comment ? <Text style={styles.comment}>{item.comment}</Text> : null}
                                    </View>
                                    <Text style={styles.colRating}>{item.rating}</Text>
                                    <Text style={styles.colStatus}>
                                        {recoveryStatusLabel(item.recovery_status)}
                                        {item.recovery_assigned_to ? ` • ${item.recovery_assigned_to}` : ''}
                                    </Text>
                                    <Text style={styles.colDate}>
                                        {item.submitted_at ? moment(item.submitted_at).format('DD/MM/YYYY HH:mm') : '-'}
                                    </Text>
                                </View>
                            ))
                        ) : (
                            <Text style={styles.emptyState}>Nenhuma avaliação crítica no período.</Text>
                        )}
                    </View>
                </View>
            </Page>
        </Document>
    );
}
