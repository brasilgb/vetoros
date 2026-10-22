import { buildOrderReportSummary, orderTotal } from '@/lib/reports/order-report-summary';
import { currencyFormatter } from '@/Utils/currency-formatter';
import { orderStatusLabel } from '@/Utils/order-status';
import { Document, Image, Page, StyleSheet, Text, View } from '@react-pdf/renderer';
import moment from 'moment';

moment.locale('pt-br');

const styles = StyleSheet.create({
    page: {
        padding: 10,
        fontSize: 10,
        fontFamily: 'Helvetica',
        backgroundColor: '#FAFAFA',
    },
    title: {
        fontSize: 18,
        textAlign: 'center',
        marginBottom: 4,
        textTransform: 'uppercase',
        color: '#333',
    },
    subtitle: {
        fontSize: 12,
        textAlign: 'center',
        marginBottom: 14,
        color: '#555',
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
    tableContainer: {
        border: '1px solid #eeeded',
        borderRadius: 4,
        overflow: 'hidden',
        backgroundColor: '#FFF',
    },
    tableHeader: {
        flexDirection: 'row',
        backgroundColor: '#eeeded',
        borderBottom: '1px solid #999',
        paddingVertical: 5,
        paddingHorizontal: 6,
        fontWeight: 'bold',
    },
    tableRow: {
        flexDirection: 'row',
        borderBottom: '0.5px solid #DDD',
        paddingVertical: 4,
        paddingHorizontal: 6,
    },
    colSmall: { width: '8%', paddingVertical: 2, textAlign: 'center' },
    colMedium: { width: '15%', paddingVertical: 2, textAlign: 'left' },
    colLarge: { width: '25%', paddingVertical: 2, textAlign: 'left' },
    col: { flex: 1, textAlign: 'left', paddingVertical: 2 },
    colRight: { width: '15%', paddingVertical: 2, textAlign: 'right' },
    footer: {
        marginTop: 14,
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
    logoPlaceholder: { paddingVertical: 4, width: 40, height: 40, justifyContent: 'center', alignItems: 'center', minWidth: '100%' },
});

export default function OrderReportPDF({ data, reportMeta, dateRange, company }: ReportPDFProps) {
    const {
        totalGeral,
        deliveredCount,
        avgTicket,
        warrantyReturnCount,
        warrantyReturnRate,
        warrantyThreshold,
        warrantyAlert,
        warrantySeverity,
        severityColors,
        topWarrantyTechnicians,
    } = buildOrderReportSummary(data, reportMeta);
    const period =
        dateRange?.from && dateRange?.to
            ? `${moment(dateRange.from).format('DD/MM/YYYY')} - ${moment(dateRange.to).format('DD/MM/YYYY')}`
            : 'Período não informado';

    return (
        <Document>
            <Page size="A4" style={styles.page}>
                <View style={styles.logoPlaceholder}>
                    <Image source={`${company?.logo ? `/storage/logos/${company?.logo}` : '/images/default.png'}`} />
                </View>
                <Text style={styles.title}>{company?.companyname}</Text>
                <Text style={styles.subtitle}>Relatório de Ordens de Serviço</Text>
                <Text style={styles.headerInfo}>
                    Período: {period} {'\n'}
                    Emitido em: {moment().format('DD/MM/YYYY HH:mm')}
                </Text>
                <Text style={[styles.headerInfo, { color: warrantyAlert ? '#b45309' : '#166534' }]}>
                    Severidade: {warrantySeverity} | Taxa de retorno em garantia: {warrantyReturnRate}% | Limite: {warrantyThreshold}%
                </Text>
                <View
                    style={[
                        styles.severityBox,
                        { backgroundColor: severityColors.backgroundColor, border: `1px solid ${severityColors.borderColor}` },
                    ]}
                >
                    <Text style={[styles.severityTitle, { color: severityColors.color }]}>Indicador de Garantia</Text>
                    <Text style={[styles.severityText, { color: severityColors.color }]}>
                        {warrantySeverity} | Taxa atual {warrantyReturnRate}% | Limite configurado {warrantyThreshold}%
                    </Text>
                </View>

                {/* Tabela */}
                <View style={styles.tableContainer}>
                    <View style={styles.tableHeader}>
                        <Text style={styles.colSmall}>#</Text>
                        <Text style={styles.colLarge}>Cliente</Text>
                        <Text style={styles.colMedium}>Modelo</Text>
                        <Text style={styles.col}>Técnico</Text>
                        <Text style={styles.colMedium}>Status</Text>
                        <Text style={styles.colMedium}>Garantia</Text>
                        <Text style={styles.colRight}>Valor (R$)</Text>
                    </View>

                    {data.map((order: ReportRow) => (
                        <View key={order.id} style={styles.tableRow}>
                            <Text style={styles.colSmall}>{order.order_number}</Text>
                            <Text style={styles.colLarge}>{order.customer?.name || 'N/A'}</Text>
                            <Text style={styles.colMedium}>{order.model || '—'}</Text>
                            <Text style={styles.col}>{order.user?.name || '—'}</Text>
                            <Text style={styles.colMedium}>{orderStatusLabel(Number(order.service_status))}</Text>
                            <Text style={styles.colMedium}>{order.is_warranty_return ? 'Retorno' : '—'}</Text>
                            <Text style={styles.colRight}>{currencyFormatter(orderTotal(order))}</Text>
                        </View>
                    ))}
                </View>

                {/* Rodapé */}
                <View style={styles.footer}>
                    <View style={styles.footerCards}>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Ordens no período</Text>
                            <Text style={styles.footerCardValue}>{data.length}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Entregues</Text>
                            <Text style={styles.footerCardValue}>{deliveredCount}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Valor Total</Text>
                            <Text style={styles.footerCardValue}>{currencyFormatter(totalGeral)}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Ticket Médio</Text>
                            <Text style={styles.footerCardValue}>{currencyFormatter(avgTicket)}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Retorno em Garantia</Text>
                            <Text style={styles.footerCardValue}>{warrantyReturnCount}</Text>
                        </View>
                    </View>
                </View>

                {topWarrantyTechnicians.length > 0 && (
                    <View style={styles.footer}>
                        <View style={styles.footerCards}>
                            {topWarrantyTechnicians.map((item) => (
                                <View key={item.label} style={styles.footerCard}>
                                    <Text style={styles.footerCardLabel}>Técnico com retorno</Text>
                                    <Text style={styles.footerCardValue}>
                                        {item.label}: {item.total}
                                    </Text>
                                </View>
                            ))}
                        </View>
                    </View>
                )}
            </Page>
        </Document>
    );
}
