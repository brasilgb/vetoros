import { currencyFormatter } from '@/Utils/currency-formatter';
import { Document, Image, Page, StyleSheet, Text, View } from '@react-pdf/renderer';
import moment from 'moment';

moment.locale('pt-br');

const styles = StyleSheet.create({
    page: { padding: 30, fontSize: 10 },
    title: { fontSize: 16, textAlign: 'center', marginBottom: 4 },
    subtitle: { fontSize: 11, textAlign: 'center', marginBottom: 15 },
    headerInfo: { fontSize: 10, marginBottom: 15, textAlign: 'center' },
    tableHeader: {
        flexDirection: 'row',
        borderBottom: '1px solid #000',
        paddingBottom: 4,
        marginBottom: 4,
        fontWeight: 'bold',
    },
    tableRow: {
        flexDirection: 'row',
        borderBottom: '0.5px solid #ccc',
        paddingVertical: 2,
    },
    colNumber: { width: '12%', textAlign: 'center' },
    colDate: { width: '14%', textAlign: 'center' },
    colDescription: { width: '34%', textAlign: 'left' },
    colCategory: { width: '16%', textAlign: 'left' },
    colCreatedBy: { width: '12%', textAlign: 'left' },
    colMoney: { width: '12%', textAlign: 'right' },
    footer: {
        marginTop: 10,
        borderTop: '1px solid #000',
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

export default function ExpenseReportPDF({ data, dateRange, company }: ReportPDFProps) {
    const period =
        dateRange?.from && dateRange?.to
            ? `${moment(dateRange.from).format('DD/MM/YYYY')} - ${moment(dateRange.to).format('DD/MM/YYYY')}`
            : 'Período não informado';

    const totalExpenses = data.reduce((acc: number, expense: ReportRow) => acc + Number(expense.amount || 0), 0);
    const uniqueCategories = new Set(data.map((expense: ReportRow) => String(expense.category || '').trim()).filter(Boolean)).size;
    const avgExpense = data.length ? totalExpenses / data.length : 0;

    return (
        <Document>
            <Page size="A4" style={styles.page}>
                <View style={styles.logoPlaceholder}>
                    <Image source={`${company?.logo ? `/storage/logos/${company?.logo}` : '/images/default.png'}`} />
                </View>
                <Text style={styles.title}>{company?.companyname}</Text>
                <Text style={styles.subtitle}>Relatório de Despesas</Text>
                <Text style={styles.headerInfo}>
                    Período: {period}
                    {'\n'}
                    Emitido em: {moment().format('DD/MM/YYYY HH:mm')}
                </Text>

                <View style={styles.tableHeader}>
                    <Text style={styles.colNumber}>#</Text>
                    <Text style={styles.colDate}>Data</Text>
                    <Text style={styles.colDescription}>Descrição</Text>
                    <Text style={styles.colCategory}>Categoria</Text>
                    <Text style={styles.colCreatedBy}>Lançado por</Text>
                    <Text style={styles.colMoney}>Valor</Text>
                </View>

                {data.map((expense: ReportRow) => (
                    <View key={expense.id} style={styles.tableRow}>
                        <Text style={styles.colNumber}>{expense.expense_number || expense.id}</Text>
                        <Text style={styles.colDate}>{moment(expense.expense_date).format('DD/MM/YYYY')}</Text>
                        <Text style={styles.colDescription}>{expense.description || '-'}</Text>
                        <Text style={styles.colCategory}>{expense.category || '-'}</Text>
                        <Text style={styles.colCreatedBy}>{expense.created_by?.name || expense.createdBy?.name || '-'}</Text>
                        <Text style={styles.colMoney}>{currencyFormatter(expense.amount || 0)}</Text>
                    </View>
                ))}

                <View style={styles.footer}>
                    <View style={styles.footerCards}>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Nº Despesas</Text>
                            <Text style={styles.footerCardValue}>{data.length}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Categorias</Text>
                            <Text style={styles.footerCardValue}>{uniqueCategories}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Média</Text>
                            <Text style={styles.footerCardValue}>{currencyFormatter(avgExpense)}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Total</Text>
                            <Text style={styles.footerCardValue}>{currencyFormatter(totalExpenses)}</Text>
                        </View>
                    </View>
                </View>
            </Page>
        </Document>
    );
}
