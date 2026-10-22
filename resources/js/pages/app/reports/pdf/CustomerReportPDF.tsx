import { maskCpfCnpj, maskPhone } from '@/Utils/mask';
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
    colMedium: { width: '18%', paddingVertical: 2, textAlign: 'left' },
    colLarge: { width: '22%', paddingVertical: 2, textAlign: 'left' },
    col: { flex: 1, textAlign: 'left', paddingVertical: 2 },
    colRight: { width: '8%', paddingVertical: 2, textAlign: 'right' },
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

export default function CustomerReportPDF({ data, dateRange, company }: ReportPDFProps) {
    const period =
        dateRange?.from && dateRange?.to
            ? `${moment(dateRange.from).format('DD/MM/YYYY')} - ${moment(dateRange.to).format('DD/MM/YYYY')}`
            : 'Período não informado';

    const customersWithEmail = data.filter((customer: ReportRow) => Boolean(customer.email)).length;
    const customersWithPhone = data.filter((customer: ReportRow) => Boolean(customer.phone)).length;
    const customersWithCpfCnpj = data.filter((customer: ReportRow) => Boolean(customer.cpfcnpj)).length;

    return (
        <Document>
            <Page size="A4" style={styles.page}>
                <View style={styles.logoPlaceholder}>
                    <Image source={`${company?.logo ? `/storage/logos/${company?.logo}` : '/images/default.png'}`} />
                </View>
                <Text style={styles.title}>{company?.companyname}</Text>
                <Text style={styles.subtitle}>Relatório de Clientes</Text>
                <Text style={styles.headerInfo}>
                    Período: {period} {'\n'}
                    Emitido em: {moment().format('DD/MM/YYYY HH:mm')}
                </Text>

                {/* Tabela */}
                <View style={styles.tableContainer}>
                    <View style={styles.tableHeader}>
                        <Text style={styles.colSmall}>#</Text>
                        <Text style={styles.colLarge}>Cliente</Text>
                        <Text style={styles.colMedium}>E-mail</Text>
                        <Text style={styles.colMedium}>CPF/CNPJ</Text>
                        <Text style={styles.colMedium}>Telefone</Text>
                        <Text style={styles.colRight}>Cadastro</Text>
                    </View>

                    {data.map((customer: ReportRow) => (
                        <View key={customer.id} style={styles.tableRow}>
                            <Text style={styles.colSmall}>{customer.customer_number}</Text>
                            <Text style={styles.colLarge}>{customer.name}</Text>
                            <Text style={styles.colMedium}>{customer.email}</Text>
                            <Text style={styles.colMedium}>{maskCpfCnpj(customer.cpfcnpj ?? '')}</Text>
                            <Text style={styles.colMedium}>{maskPhone(customer.phone ?? '')}</Text>
                            <Text style={styles.colRight}>{moment(customer.created_at).format('DD/MM/YYYY')}</Text>
                        </View>
                    ))}
                </View>

                <View style={styles.footer}>
                    <View style={styles.footerCards}>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Clientes no período</Text>
                            <Text style={styles.footerCardValue}>{data.length}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Com e-mail</Text>
                            <Text style={styles.footerCardValue}>{customersWithEmail}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Com telefone</Text>
                            <Text style={styles.footerCardValue}>{customersWithPhone}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Com CPF/CNPJ</Text>
                            <Text style={styles.footerCardValue}>{customersWithCpfCnpj}</Text>
                        </View>
                    </View>
                </View>
            </Page>
        </Document>
    );
}
