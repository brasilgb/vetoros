import { partTypeLabel } from '@/lib/reports/report-labels';
import { currencyFormatter } from '@/Utils/currency-formatter';
import { Document, Image, Page, StyleSheet, Text, View } from '@react-pdf/renderer';
import moment from 'moment';

moment.locale('pt-br');

const styles = StyleSheet.create({
    page: {
        padding: 16,
        fontSize: 9,
        fontFamily: 'Helvetica',
        backgroundColor: '#FAFAFA',
    },
    title: {
        fontSize: 15,
        textAlign: 'center',
        marginBottom: 4,
        textTransform: 'uppercase',
        color: '#333',
    },
    subtitle: {
        fontSize: 10,
        textAlign: 'center',
        marginBottom: 8,
        color: '#555',
    },
    headerInfo: {
        fontSize: 10,
        textAlign: 'center',
        marginBottom: 10,
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
        paddingVertical: 6,
        paddingHorizontal: 8,
        fontWeight: 'bold',
    },
    tableRow: {
        flexDirection: 'row',
        borderBottom: '0.5px solid #DDD',
        paddingVertical: 5,
        paddingHorizontal: 8,
    },
    colType: { width: '10%', textAlign: 'center' },
    colRef: { width: '12%', textAlign: 'center' },
    colName: { width: '30%', textAlign: 'left' },
    colManufacturer: { width: '16%', textAlign: 'left' },
    colQty: { width: '7%', textAlign: 'right' },
    colMin: { width: '7%', textAlign: 'right' },
    colCost: { width: '9%', textAlign: 'right' },
    colSale: { width: '9%', textAlign: 'right' },
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
    logoPlaceholder: { paddingVertical: 2, width: 38, height: 38, justifyContent: 'center', alignItems: 'center', minWidth: '100%' },
});

export default function PartReportPDF({ data, dateRange, company }: ReportPDFProps) {

    const period =
        dateRange?.from && dateRange?.to
            ? `${moment(dateRange.from).format('DD/MM/YYYY')} - ${moment(dateRange.to).format('DD/MM/YYYY')}`
            : 'Período não informado';
    const totalItems = data.length;
    const stockTotal = data.reduce((acc: number, part: ReportRow) => acc + Number(part.quantity || 0), 0);
    const lowStock = data.filter((part: ReportRow) => Number(part.quantity || 0) <= Number(part.minimum_stock_level || 0)).length;
    const stockCost = data.reduce((acc: number, part: ReportRow) => acc + Number(part.quantity || 0) * Number(part.cost_price || 0), 0);
    const stockSale = data.reduce((acc: number, part: ReportRow) => acc + Number(part.quantity || 0) * Number(part.sale_price || 0), 0);

    return (
        <Document>
            <Page size="A4" orientation="landscape" style={styles.page}>
                <View style={styles.logoPlaceholder}>
                    <Image source={`${company?.logo ? `/storage/logos/${company?.logo}` : '/images/default.png'}`} />
                </View>
                <Text style={styles.title}>{company?.companyname}</Text>
                <Text style={styles.subtitle}>Relatório de movimento de produtos e peças</Text>
                <Text style={styles.headerInfo}>
                    Período: {period} {'\n'}
                    Emitido em: {moment().format('DD/MM/YYYY HH:mm')}
                </Text>

                {/* Tabela */}
                <View style={styles.tableContainer}>
                    <View style={styles.tableHeader}>
                        <Text style={styles.colType}>Tipo</Text>
                        <Text style={styles.colRef}>Referência</Text>
                        <Text style={styles.colName}>Nome</Text>
                        <Text style={styles.colManufacturer}>Fabricante</Text>
                        <Text style={styles.colQty}>Qtd.</Text>
                        <Text style={styles.colMin}>Mín.</Text>
                        <Text style={styles.colCost}>Custo</Text>
                        <Text style={styles.colSale}>Venda</Text>
                    </View>

                    {data.map((part: ReportRow) => (
                        <View key={part.id} style={styles.tableRow}>
                            <Text style={styles.colType}>{partTypeLabel(part.type)}</Text>
                            <Text style={styles.colRef}>{part.reference_number || '-'}</Text>
                            <Text style={styles.colName}>{part.name || '-'}</Text>
                            <Text style={styles.colManufacturer}>{part.manufacturer || '-'}</Text>
                            <Text style={styles.colQty}>{Number(part.quantity || 0)}</Text>
                            <Text style={styles.colMin}>{Number(part.minimum_stock_level || 0)}</Text>
                            <Text style={styles.colCost}>{currencyFormatter(part.cost_price ?? 0)}</Text>
                            <Text style={styles.colSale}>{currencyFormatter(part.sale_price ?? 0)}</Text>
                        </View>
                    ))}
                </View>

                <View style={styles.footer}>
                    <View style={styles.footerCards}>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Itens</Text>
                            <Text style={styles.footerCardValue}>{totalItems}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Quantidade em estoque</Text>
                            <Text style={styles.footerCardValue}>{stockTotal}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Abaixo do mínimo</Text>
                            <Text style={styles.footerCardValue}>{lowStock}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Estoque a custo</Text>
                            <Text style={styles.footerCardValue}>{currencyFormatter(stockCost)}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Estoque a venda</Text>
                            <Text style={styles.footerCardValue}>{currencyFormatter(stockSale)}</Text>
                        </View>
                    </View>
                </View>
            </Page>
        </Document>
    );
}
