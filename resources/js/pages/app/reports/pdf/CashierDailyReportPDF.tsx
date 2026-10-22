import { currencyFormatter } from '@/Utils/currency-formatter';
import { Document, Image, Page, StyleSheet, Text, View } from '@react-pdf/renderer';
import moment from 'moment';

moment.locale('pt-br');

const styles = StyleSheet.create({
    page: { padding: 24, fontSize: 10, fontFamily: 'Helvetica' },
    logoPlaceholder: { paddingVertical: 4, width: 40, height: 40, justifyContent: 'center', alignItems: 'center', minWidth: '100%' },
    title: { fontSize: 16, textAlign: 'center', marginBottom: 4 },
    subtitle: { fontSize: 11, textAlign: 'center', marginBottom: 12 },
    headerInfo: { fontSize: 10, marginBottom: 14, textAlign: 'center' },
    sectionTitle: { fontSize: 11, marginBottom: 6, fontWeight: 'bold', color: '#1f2937' },
    section: { marginBottom: 12 },
    table: { border: '1px solid #d6d6d6', borderRadius: 4, overflow: 'hidden' },
    row: { flexDirection: 'row', borderBottom: '0.5px solid #e4e4e4', paddingVertical: 5, paddingHorizontal: 8 },
    label: { width: '55%', color: '#333' },
    value: { width: '45%', textAlign: 'right', fontWeight: 'bold' },
    helper: { fontSize: 8, color: '#6b7280', marginTop: 2, textAlign: 'right' },
    noteBox: { border: '1px solid #d6d6d6', borderRadius: 4, padding: 8, marginBottom: 8 },
    noteLabel: { fontSize: 9, color: '#6b7280', marginBottom: 4 },
    noteText: { fontSize: 10, color: '#111827' },
    footer: { marginTop: 12, borderTop: '1px solid #d6d6d6', paddingTop: 6, textAlign: 'center' },
});

export default function CashierDailyReportPDF({ session, company }: CashierDailyReportProps) {
    const openDate = session?.opened_at ? moment(session.opened_at).format('DD/MM/YYYY HH:mm') : '-';
    const closeDate = session?.closed_at ? moment(session.closed_at).format('DD/MM/YYYY HH:mm') : '-';
    const reportDate = session?.closed_at
        ? moment(session.closed_at).format('DD/MM/YYYY')
        : session?.opened_at
          ? moment(session.opened_at).format('DD/MM/YYYY')
          : 'Data não informada';
    const paymentMethodLabels: Record<string, string> = {
        pix: 'Pix',
        cartao: 'Cartão',
        dinheiro: 'Dinheiro',
        transferencia: 'Transferência',
        boleto: 'Boleto',
    };
    const paymentMethods = ['pix', 'cartao', 'dinheiro', 'transferencia', 'boleto'];
    const orderPaymentTotalsByMethod = (session?.order_payments || []).reduce((acc: Record<string, number>, payment: ReportRow) => {
        const method = String(payment?.payment_method || '').toLowerCase();
        if (!acc[method]) acc[method] = 0;
        acc[method] += Number(payment?.amount || 0);
        return acc;
    }, {});
    const salesTotalsByMethod = (session?.sales || []).reduce((acc: Record<string, number>, sale: ReportSale) => {
        if (sale?.status !== 'completed') return acc;
        const method = String(sale?.payment_method || '').toLowerCase();
        if (!acc[method]) acc[method] = 0;
        acc[method] += Number(sale?.total_amount || 0);
        return acc;
    }, {});
    const paymentMethodsWithMovement = paymentMethods.filter(
        (method) => Number(salesTotalsByMethod[method] || 0) > 0 || Number(orderPaymentTotalsByMethod[method] || 0) > 0,
    );
    const transparentRowStyle = { borderBottom: '0px solid transparent' } as const;
    const openingNotes = String(session?.notes || '').trim();
    const closingNotes = String(session?.closing_notes || '').trim();
    const withdrawals = session?.withdrawals || [];
    const activeWithdrawals = withdrawals.filter((withdrawal: ReportRow) => !withdrawal?.cancelled_at);
    const withdrawalsTotal = activeWithdrawals.reduce((sum: number, withdrawal: ReportRow) => sum + Number(withdrawal?.amount || 0), 0);

    return (
        <Document>
            <Page size="A4" style={styles.page}>
                <View style={styles.logoPlaceholder}>
                    <Image source={`${company?.logo ? `/storage/logos/${company.logo}` : '/images/default.png'}`} />
                </View>
                <Text style={styles.title}>{company?.companyname || 'Empresa'}</Text>
                <Text style={styles.subtitle}>Relatório de Fechamento Diário de Caixa</Text>
                <Text style={styles.headerInfo}>
                    Data: {reportDate}
                    {'\n'}
                    Emitido em: {moment().format('DD/MM/YYYY HH:mm')}
                </Text>

                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Resumo do fechamento</Text>
                    <View style={styles.table}>
                        <View style={styles.row}>
                            <Text style={styles.label}>Sessão de caixa</Text>
                            <Text style={styles.value}>#{session?.id}</Text>
                        </View>
                        <View style={styles.row}>
                            <Text style={styles.label}>Abertura</Text>
                            <Text style={styles.value}>{openDate}</Text>
                        </View>
                        <View style={styles.row}>
                            <Text style={styles.label}>Fechamento</Text>
                            <Text style={styles.value}>{closeDate}</Text>
                        </View>
                        <View style={styles.row}>
                            <Text style={styles.label}>Saldo inicial</Text>
                            <Text style={styles.value}>{currencyFormatter(session?.opening_balance || 0)}</Text>
                        </View>
                        <View style={styles.row}>
                            <Text style={styles.label}>Entradas manuais</Text>
                            <Text style={styles.value}>{currencyFormatter(session?.manual_entries || 0)}</Text>
                        </View>
                        <View style={styles.row}>
                            <Text style={styles.label}>Saídas manuais</Text>
                            <Text style={styles.value}>{currencyFormatter(session?.manual_exits || 0)}</Text>
                        </View>
                        <View style={styles.row}>
                            <Text style={styles.label}>Sangrias registradas</Text>
                            <Text style={styles.value}>{currencyFormatter(withdrawalsTotal)}</Text>
                        </View>
                        <View style={styles.row}>
                            <Text style={styles.label}>Saldo esperado</Text>
                            <Text style={styles.value}>{currencyFormatter(session?.expected_balance || 0)}</Text>
                        </View>
                        <View style={styles.row}>
                            <Text style={styles.label}>Saldo contado</Text>
                            <Text style={styles.value}>{currencyFormatter(session?.closing_balance || 0)}</Text>
                        </View>
                        <View style={[styles.row, { borderBottom: '0px solid transparent' }]}>
                            <Text style={styles.label}>Diferença</Text>
                            <Text style={styles.value}>{currencyFormatter(session?.difference || 0)}</Text>
                        </View>
                    </View>
                </View>

                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Composição do caixa</Text>
                    <View style={styles.table}>
                        <View style={styles.row}>
                            <Text style={styles.label}>Vendas concluídas</Text>
                            <Text style={styles.value}>{currencyFormatter(session?.total_completed_sales || 0)}</Text>
                        </View>
                        <View style={styles.row}>
                            <Text style={styles.label}>Pagamentos de ordens de serviço</Text>
                            <Text style={styles.value}>{currencyFormatter(session?.total_order_payments || 0)}</Text>
                        </View>
                        <View style={[styles.row, { borderBottom: '0px solid transparent' }]}>
                            <Text style={styles.label}>Vendas canceladas</Text>
                            <Text style={styles.value}>{currencyFormatter(session?.total_cancelled_sales || 0)}</Text>
                        </View>
                    </View>
                </View>

                {activeWithdrawals.length ? (
                    <View style={styles.section}>
                        <Text style={styles.sectionTitle}>Sangrias</Text>
                        <View style={styles.table}>
                            {activeWithdrawals.map((withdrawal: ReportRow, index: number) => (
                                <View
                                    key={withdrawal.id || index}
                                    style={[styles.row, ...(index === activeWithdrawals.length - 1 ? [transparentRowStyle] : [])]}
                                >
                                    <View style={styles.label}>
                                        <Text>{withdrawal.description || 'Sangria'}</Text>
                                        <Text style={styles.helper}>
                                            {withdrawal.created_at ? moment(withdrawal.created_at).format('DD/MM/YYYY HH:mm') : '-'} |{' '}
                                            {withdrawal.user?.name || '-'}
                                        </Text>
                                    </View>
                                    <Text style={styles.value}>{currencyFormatter(withdrawal.amount || 0)}</Text>
                                </View>
                            ))}
                        </View>
                    </View>
                ) : null}

                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Recebimentos por forma de pagamento</Text>
                    <View style={styles.table}>
                        {paymentMethodsWithMovement.length ? (
                            paymentMethodsWithMovement.map((method, index) => (
                                <View
                                    key={method}
                                    style={[styles.row, ...(index === paymentMethodsWithMovement.length - 1 ? [transparentRowStyle] : [])]}
                                >
                                    <View style={styles.label}>
                                        <Text>{paymentMethodLabels[method]}</Text>
                                        <Text style={styles.helper}>
                                            Vendas {currencyFormatter(salesTotalsByMethod[method] || 0)} | OS{' '}
                                            {currencyFormatter(orderPaymentTotalsByMethod[method] || 0)}
                                        </Text>
                                    </View>
                                    <Text style={styles.value}>
                                        {currencyFormatter((salesTotalsByMethod[method] || 0) + (orderPaymentTotalsByMethod[method] || 0))}
                                    </Text>
                                </View>
                            ))
                        ) : (
                            <View style={[styles.row, { borderBottom: '0px solid transparent' }]}>
                                <Text style={styles.label}>Sem movimentações por forma de pagamento</Text>
                                <Text style={styles.value}>{currencyFormatter(0)}</Text>
                            </View>
                        )}
                    </View>
                </View>

                {(openingNotes || closingNotes) && (
                    <View style={styles.section}>
                        <Text style={styles.sectionTitle}>Observações</Text>
                        {openingNotes ? (
                            <View style={styles.noteBox}>
                                <Text style={styles.noteLabel}>Abertura</Text>
                                <Text style={styles.noteText}>{openingNotes}</Text>
                            </View>
                        ) : null}
                        {closingNotes ? (
                            <View style={styles.noteBox}>
                                <Text style={styles.noteLabel}>Fechamento</Text>
                                <Text style={styles.noteText}>{closingNotes}</Text>
                            </View>
                        ) : null}
                    </View>
                )}

                <View style={styles.footer}>
                    <Text>Responsável abertura: {session?.opened_by?.name || '-'}</Text>
                    <Text>Responsável fechamento: {session?.closed_by?.name || '-'}</Text>
                </View>
            </Page>
        </Document>
    );
}
