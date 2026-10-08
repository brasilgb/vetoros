import { currencyFormatter } from '@/Utils/currency-formatter';
import { ORDER_STATUS, ORDER_STATUSES_COMPLETED, orderStatusRank } from '@/Utils/order-status';
import { Document, Image, Page, StyleSheet, Text, View } from '@react-pdf/renderer';
import moment from 'moment';

const styles = StyleSheet.create({
    page: {
        padding: 14,
        fontSize: 10,
        fontFamily: 'Helvetica',
    },

    title: {
        fontSize: 16,
        textAlign: 'center',
        marginBottom: 6,
    },

    subtitle: {
        textAlign: 'center',
        marginBottom: 12,
    },
    headerInfo: {
        fontSize: 10,
        textAlign: 'center',
        marginBottom: 12,
        color: '#666',
    },
    severityBox: {
        borderRadius: 4,
        paddingVertical: 8,
        paddingHorizontal: 10,
        marginBottom: 12,
    },
    severityTitle: {
        fontSize: 9,
        marginBottom: 3,
        textTransform: 'uppercase',
    },
    severityText: {
        fontSize: 11,
        fontWeight: 'bold',
    },

    table: {
        border: '1px solid #DDD',
    },

    headerRow: {
        flexDirection: 'row',
        backgroundColor: '#EEE',
        borderBottom: '1px solid #AAA',
        paddingVertical: 5,
    },

    row: {
        flexDirection: 'row',
        borderBottom: '0.5px solid #DDD',
        paddingVertical: 5,
    },

    colName: {
        width: '40%',
        paddingLeft: 6,
    },

    colCenter: {
        width: '20%',
        textAlign: 'center',
    },

    colMoney: {
        width: '20%',
        textAlign: 'right',
        paddingRight: 6,
    },

    footer: {
        marginTop: 10,
        borderTop: '1px solid #AAA',
        paddingTop: 6,
    },
    footerCards: {
        flexDirection: 'row',
        flexWrap: 'wrap',
        gap: 6,
    },
    footerCard: {
        width: '32%',
        border: '1px solid #d9d9d9',
        borderRadius: 4,
        paddingVertical: 6,
        paddingHorizontal: 8,
        backgroundColor: '#f7f7f7',
    },
    footerCardLabel: {
        fontSize: 8,
        color: '#555',
        marginBottom: 2,
        textTransform: 'uppercase',
    },
    footerCardValue: {
        fontSize: 10,
        fontWeight: 'bold',
        color: '#111',
    },
    metricsBox: {
        marginTop: 12,
        border: '1px solid #DDD',
        padding: 6,
    },

    metricTitle: {
        fontWeight: 'bold',
        marginBottom: 4,
    },
    logoPlaceholder: { paddingVertical: 4, width: 40, height: 40, justifyContent: 'center', alignItems: 'center', minWidth: '100%' },
});

export default function TechnicianProductivityPDF({ data, reportMeta, company, dateRange }: any) {
    const period =
        dateRange?.from && dateRange?.to
            ? `${moment(dateRange.from).format('DD/MM/YYYY')} - ${moment(dateRange.to).format('DD/MM/YYYY')}`
            : 'Período não informado';

    const statsByTechnician: any = {};
    const warrantyByTechnician: Record<string, number> = {};
    const warrantyReturnRate = Number(reportMeta?.warranty_return_rate ?? 0);
    const warrantyThreshold = Number(reportMeta?.warranty_return_threshold ?? 10);
    const warrantyAlert = Boolean(reportMeta?.warranty_return_alert ?? false);
    const warrantySeverity =
        warrantyReturnRate <= 5 ? 'Saudável' : warrantyReturnRate <= warrantyThreshold ? 'Atenção' : 'Crítico';
    const severityColors =
        warrantySeverity === 'Crítico'
            ? { backgroundColor: '#FEF3C7', borderColor: '#F59E0B', color: '#92400E' }
            : warrantySeverity === 'Atenção'
              ? { backgroundColor: '#EFF6FF', borderColor: '#60A5FA', color: '#1D4ED8' }
              : { backgroundColor: '#ECFDF5', borderColor: '#34D399', color: '#065F46' };

    data.forEach((order: any) => {
        const tech = order.technician?.name || 'Não definido';

        if (order.is_warranty_return) {
            warrantyByTechnician[tech] = (warrantyByTechnician[tech] ?? 0) + 1;
        }

        if (!ORDER_STATUSES_COMPLETED.includes(order.service_status)) return;

        if (!statsByTechnician[tech]) {
            statsByTechnician[tech] = {
                technician: tech,
                totalOrdens: 0,
                totalServicos: 0,
            };
        }

        statsByTechnician[tech].totalOrdens += 1;
        statsByTechnician[tech].totalServicos += Number(order.service_value || 0);
    });

    const rows = Object.values(statsByTechnician).map((r: any) => ({
        ...r,
        ticketMedio: r.totalServicos / r.totalOrdens,
    }));

    const completedOrders = data.filter((o: any) => ORDER_STATUSES_COMPLETED.includes(o.service_status));

    const deliveredOrders = data.filter((o: any) => o.service_status === ORDER_STATUS.DELIVERED);

    const avgRepairDays =
        completedOrders.reduce((acc: number, order: any) => {
            const start = moment(order.created_at);
            const end = moment(order.updated_at);
            return acc + end.diff(start, 'days');
        }, 0) / (completedOrders.length || 1);

    const avgDeliveryDays =
        deliveredOrders.reduce((acc: number, order: any) => {
            const start = moment(order.created_at);
            const end = moment(order.updated_at);
            return acc + end.diff(start, 'days');
        }, 0) / (deliveredOrders.length || 1);

    const pendingLongTime = data.filter((order: any) => {
        if (orderStatusRank(order.service_status) >= orderStatusRank(ORDER_STATUS.REPAIR_IN_PROGRESS)) return false;

        const days = moment().diff(moment(order.created_at), 'days');

        return days > 7;
    }).length;

    const totalOrdens = rows.reduce((acc: any, r: any) => acc + r.totalOrdens, 0);
    const totalServicos = rows.reduce((acc: any, r: any) => acc + r.totalServicos, 0);
    const warrantyRows = Object.entries(warrantyByTechnician)
        .map(([technician, totalReturns]) => ({ technician, totalReturns }))
        .sort((a, b) => b.totalReturns - a.totalReturns);
    const totalWarrantyReturns = warrantyRows.reduce((acc, row) => acc + row.totalReturns, 0);

    return (
        <Document>
            <Page size="A4" style={styles.page}>
                <View style={styles.logoPlaceholder}>
                    <Image source={`${company?.logo ? `/storage/logos/${company?.logo}` : '/images/default.png'}`} />
                </View>
                <Text style={styles.title}>{company?.companyname}</Text>
                <Text style={styles.subtitle}>Relatório de Produtividade por Técnico</Text>
                <Text style={styles.headerInfo}>
                    Período: {period} {'\n'}
                    Emitido em: {moment().format('DD/MM/YYYY HH:mm')}
                </Text>
                <Text style={[styles.headerInfo, { color: warrantyAlert ? '#b45309' : '#166534' }]}>
                    Severidade: {warrantySeverity} | Taxa de retorno em garantia: {warrantyReturnRate}% | Limite: {warrantyThreshold}%
                </Text>
                <View style={[styles.severityBox, { backgroundColor: severityColors.backgroundColor, border: `1px solid ${severityColors.borderColor}` }]}>
                    <Text style={[styles.severityTitle, { color: severityColors.color }]}>Indicador de Garantia</Text>
                    <Text style={[styles.severityText, { color: severityColors.color }]}>
                        {warrantySeverity} | Taxa atual {warrantyReturnRate}% | Limite configurado {warrantyThreshold}%
                    </Text>
                </View>

                <View style={styles.table}>
                    <View style={styles.headerRow}>
                        <Text style={styles.colName}>Técnico</Text>
                        <Text style={styles.colCenter}>Ordens</Text>
                        <Text style={styles.colMoney}>Serviços</Text>
                        <Text style={styles.colMoney}>Ticket Médio</Text>
                    </View>

                    {rows.map((row: any, index: number) => (
                        <View key={index} style={styles.row}>
                            <Text style={styles.colName}>{row.technician}</Text>

                            <Text style={styles.colCenter}>{row.totalOrdens}</Text>

                            <Text style={styles.colMoney}>{currencyFormatter(row.totalServicos)}</Text>

                            <Text style={styles.colMoney}>{currencyFormatter(row.ticketMedio)}</Text>
                        </View>
                    ))}
                </View>

                <View style={styles.footer}>
                    <View style={styles.footerCards}>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Ordens Concluídas</Text>
                            <Text style={styles.footerCardValue}>{totalOrdens}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Faturado em Serviços</Text>
                            <Text style={styles.footerCardValue}>{currencyFormatter(totalServicos)}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Ticket Médio Geral</Text>
                            <Text style={styles.footerCardValue}>{currencyFormatter(totalOrdens ? totalServicos / totalOrdens : 0)}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Retornos em Garantia</Text>
                            <Text style={styles.footerCardValue}>{totalWarrantyReturns}</Text>
                        </View>
                    </View>
                </View>

                <View style={styles.metricsBox}>
                    <Text style={styles.metricTitle}>Indicadores de Tempo de Reparo</Text>

                    <Text>Tempo médio para concluir serviço: {avgRepairDays.toFixed(1)} dias</Text>

                    <Text>Tempo médio até entrega: {avgDeliveryDays.toFixed(1)} dias</Text>

                    <Text>Equipamentos pendentes há mais de 7 dias: {pendingLongTime}</Text>
                </View>

                {warrantyRows.length > 0 && (
                    <View style={styles.metricsBox}>
                        <Text style={styles.metricTitle}>Retorno em Garantia por Técnico</Text>

                        {warrantyRows.map((row) => (
                            <Text key={row.technician}>
                                {row.technician}: {row.totalReturns}
                            </Text>
                        ))}
                    </View>
                )}
            </Page>
        </Document>
    );
}
