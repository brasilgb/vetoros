import { currencyFormatter } from '@/Utils/currency-formatter';
import { Document, Image, Page, StyleSheet, Text, View } from '@react-pdf/renderer';
import moment from 'moment';

moment.locale('pt-br');

const styles = StyleSheet.create({
    page: { padding: 18, fontSize: 9, fontFamily: 'Helvetica', backgroundColor: '#FAFAFA' },
    logoPlaceholder: { paddingVertical: 2, width: 38, height: 38, justifyContent: 'center', alignItems: 'center', minWidth: '100%' },
    title: { fontSize: 15, textAlign: 'center', marginBottom: 4, color: '#333' },
    subtitle: { fontSize: 10, textAlign: 'center', marginBottom: 8, color: '#555' },
    headerInfo: { fontSize: 9, textAlign: 'center', marginBottom: 10, color: '#666' },
    summaryGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginBottom: 10 },
    summaryCard: {
        width: '19%',
        border: '1px solid #d9d9d9',
        borderRadius: 4,
        paddingVertical: 6,
        paddingHorizontal: 8,
        backgroundColor: '#fff',
    },
    summaryLabel: { fontSize: 8, color: '#666', marginBottom: 2, textTransform: 'uppercase' },
    summaryValue: { fontSize: 9, color: '#111', fontWeight: 'bold' },
    tableContainer: { border: '1px solid #e5e5e5', borderRadius: 4, overflow: 'hidden', backgroundColor: '#fff' },
    tableHeader: {
        flexDirection: 'row',
        backgroundColor: '#efefef',
        borderBottom: '1px solid #cfcfcf',
        paddingVertical: 6,
        paddingHorizontal: 8,
    },
    tableRow: {
        flexDirection: 'row',
        borderBottom: '0.5px solid #e8e8e8',
        paddingVertical: 5,
        paddingHorizontal: 8,
    },
    colId: { width: '6%', textAlign: 'center' },
    colName: { width: '52%', textAlign: 'left' },
    colDate: { width: '15%', textAlign: 'left' },
    colMoney: { width: '10%', textAlign: 'right' },
    colDiff: { width: '10%', textAlign: 'right' },
    colUser: { width: '14%', textAlign: 'left' },
    emptyState: { paddingVertical: 10, textAlign: 'center', color: '#666' },
});

export default function CashierPeriodReportPDF({ data, dateRange, company, reportMeta }: ReportPDFProps) {
    const paymentMethodLabels: Record<string, string> = {
        pix: 'Pix',
        cartao: 'Cartão',
        dinheiro: 'Dinheiro',
        transferencia: 'Transferência',
        boleto: 'Boleto',
    };
    const paymentMethods = ['pix', 'cartao', 'dinheiro', 'transferencia', 'boleto'];
    const salesByMethod = reportMeta?.sales_by_method ?? {};
    const orderPaymentsByMethod = reportMeta?.order_payments_by_method ?? {};
    const salesTotal = Number(reportMeta?.completed_sales_total || 0);
    const orderPaymentsTotal = Number(reportMeta?.order_payments_total || 0);
    const asPercent = (value: number, total: number) => (total > 0 ? `${((value / total) * 100).toFixed(1)}%` : '0.0%');

    const period =
        dateRange?.from && dateRange?.to
            ? `${moment(dateRange.from).format('DD/MM/YYYY')} - ${moment(dateRange.to).format('DD/MM/YYYY')}`
            : 'Período não informado';

    return (
        <Document>
            <Page size="A4" orientation="landscape" style={styles.page}>
                <View style={styles.logoPlaceholder}>
                    <Image source={`${company?.logo ? `/storage/logos/${company?.logo}` : '/images/default.png'}`} />
                </View>
                <Text style={styles.title}>{company?.companyname || 'Empresa'}</Text>
                <Text style={styles.subtitle}>Relatório de Caixa por Período</Text>
                <Text style={styles.headerInfo}>
                    Período: {period} {'\n'}
                    Emitido em: {moment().format('DD/MM/YYYY HH:mm')}
                </Text>

                <View style={styles.summaryGrid}>
                    <View style={styles.summaryCard}>
                        <Text style={styles.summaryLabel}>Sessões fechadas</Text>
                        <Text style={styles.summaryValue}>{Number(reportMeta?.sessions_count || 0)}</Text>
                    </View>
                    <View style={styles.summaryCard}>
                        <Text style={styles.summaryLabel}>Total vendido</Text>
                        <Text style={styles.summaryValue}>{currencyFormatter(reportMeta?.completed_sales_total || 0)}</Text>
                    </View>
                    <View style={styles.summaryCard}>
                        <Text style={styles.summaryLabel}>Pagamentos de OS</Text>
                        <Text style={styles.summaryValue}>{currencyFormatter(reportMeta?.order_payments_total || 0)}</Text>
                    </View>
                    <View style={styles.summaryCard}>
                        <Text style={styles.summaryLabel}>Sangrias</Text>
                        <Text style={styles.summaryValue}>{currencyFormatter(reportMeta?.withdrawals_total || 0)}</Text>
                    </View>
                    <View style={styles.summaryCard}>
                        <Text style={styles.summaryLabel}>Diferença total</Text>
                        <Text style={styles.summaryValue}>{currencyFormatter(reportMeta?.difference_total || 0)}</Text>
                    </View>
                </View>

                <View style={styles.tableContainer}>
                    <View style={styles.tableHeader}>
                        <Text style={styles.colName}>Forma de pagamento</Text>
                        <Text style={styles.colMoney}>Vendas</Text>
                        <Text style={styles.colMoney}>Pagamentos OS</Text>
                    </View>
                    {paymentMethods.map((method) => (
                        <View key={method} style={styles.tableRow}>
                            <Text style={styles.colName}>{paymentMethodLabels[method]}</Text>
                            <Text style={styles.colMoney}>
                                {currencyFormatter(Number(salesByMethod[method] || 0))} ({asPercent(Number(salesByMethod[method] || 0), salesTotal)})
                            </Text>
                            <Text style={styles.colMoney}>
                                {currencyFormatter(Number(orderPaymentsByMethod[method] || 0))} (
                                {asPercent(Number(orderPaymentsByMethod[method] || 0), orderPaymentsTotal)})
                            </Text>
                        </View>
                    ))}
                </View>

                <View style={{ height: 8 }} />

                <View style={styles.tableContainer}>
                    <View style={styles.tableHeader}>
                        <Text style={styles.colId}>#</Text>
                        <Text style={styles.colDate}>Abertura</Text>
                        <Text style={styles.colDate}>Fechamento</Text>
                        <Text style={styles.colMoney}>Saldo Inicial</Text>
                        <Text style={styles.colMoney}>Sangrias</Text>
                        <Text style={styles.colMoney}>Saldo Esperado</Text>
                        <Text style={styles.colMoney}>Saldo Contado</Text>
                        <Text style={styles.colDiff}>Diferença</Text>
                        <Text style={styles.colUser}>Fechado por</Text>
                    </View>

                    {Array.isArray(data) && data.length > 0 ? (
                        data.map((session: ReportRow) => (
                            <View key={session.id} style={styles.tableRow}>
                                <Text style={styles.colId}>{session.id}</Text>
                                <Text style={styles.colDate}>{session.opened_at ? moment(session.opened_at).format('DD/MM/YYYY HH:mm') : '-'}</Text>
                                <Text style={styles.colDate}>{session.closed_at ? moment(session.closed_at).format('DD/MM/YYYY HH:mm') : '-'}</Text>
                                <Text style={styles.colMoney}>{currencyFormatter(session.opening_balance || 0)}</Text>
                                <Text style={styles.colMoney}>
                                    {currencyFormatter(
                                        (session.withdrawals || []).reduce(
                                            (sum: number, withdrawal: ReportRow) => (withdrawal.cancelled_at ? sum : sum + Number(withdrawal.amount || 0)),
                                            0,
                                        ),
                                    )}
                                </Text>
                                <Text style={styles.colMoney}>{currencyFormatter(session.expected_balance || 0)}</Text>
                                <Text style={styles.colMoney}>{currencyFormatter(session.closing_balance || 0)}</Text>
                                <Text style={styles.colDiff}>{currencyFormatter(session.difference || 0)}</Text>
                                <Text style={styles.colUser}>{session.closed_by?.name || '-'}</Text>
                            </View>
                        ))
                    ) : (
                        <Text style={styles.emptyState}>Nenhuma sessão de caixa fechada no período selecionado.</Text>
                    )}
                </View>
            </Page>
        </Document>
    );
}
