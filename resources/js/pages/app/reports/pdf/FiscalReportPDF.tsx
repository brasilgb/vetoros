import { fiscalProviderLabel, fiscalTargetLabel } from '@/lib/reports/report-labels';
import { Document, Image, Page, StyleSheet, Text, View } from '@react-pdf/renderer';
import moment from 'moment';

moment.locale('pt-br');

const styles = StyleSheet.create({
    page: { padding: 30, fontSize: 9 },
    title: { fontSize: 16, textAlign: 'center', marginBottom: 4 },
    subtitle: { fontSize: 11, textAlign: 'center', marginBottom: 15 },
    headerInfo: { fontSize: 10, marginBottom: 15, textAlign: 'center' },
    tableHeader: { flexDirection: 'row', borderBottom: '1px solid #000', paddingBottom: 4, marginBottom: 4, fontWeight: 'bold' },
    tableRow: { flexDirection: 'row', borderBottom: '0.5px solid #ccc', paddingVertical: 2 },
    colType: { width: '10%', textAlign: 'center' },
    colProvider: { width: '13%', textAlign: 'left' },
    colTarget: { width: '16%', textAlign: 'left' },
    colNumber: { width: '17%', textAlign: 'left' },
    colStatus: { width: '13%', textAlign: 'left' },
    colDate: { width: '15%', textAlign: 'center' },
    colUser: { width: '16%', textAlign: 'left' },
    footer: { marginTop: 10, borderTop: '1px solid #000', paddingTop: 6 },
    footerCards: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
    footerCard: { width: '32%', border: '1px solid #d9d9d9', borderRadius: 4, paddingVertical: 6, paddingHorizontal: 8, backgroundColor: '#f7f7f7' },
    footerCardLabel: { fontSize: 8, color: '#555', marginBottom: 2, textTransform: 'uppercase' },
    footerCardValue: { fontSize: 10, fontWeight: 'bold', color: '#111' },
    logoPlaceholder: { paddingVertical: 4, width: 40, height: 40, justifyContent: 'center', alignItems: 'center', minWidth: '100%' },
});

export default function FiscalReportPDF({ data, reportMeta, dateRange, company }: ReportPDFProps) {
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
                <Text style={styles.subtitle}>Relatório Fiscal</Text>
                <Text style={styles.headerInfo}>
                    Período: {period}
                    {'\n'}
                    Emitido em: {moment().format('DD/MM/YYYY HH:mm')}
                </Text>

                <View style={styles.tableHeader}>
                    <Text style={styles.colType}>Tipo</Text>
                    <Text style={styles.colProvider}>Origem</Text>
                    <Text style={styles.colTarget}>Vínculo</Text>
                    <Text style={styles.colNumber}>Número</Text>
                    <Text style={styles.colStatus}>Status</Text>
                    <Text style={styles.colDate}>Emissão</Text>
                    <Text style={styles.colUser}>Registrado por</Text>
                </View>

                {data.map((document: ReportRow) => (
                    <View key={document.id} style={styles.tableRow}>
                        <Text style={styles.colType}>{String(document.type || '').toUpperCase()}</Text>
                        <Text style={styles.colProvider}>{fiscalProviderLabel(document.provider ?? '')}</Text>
                        <Text style={styles.colTarget}>{fiscalTargetLabel(document)}</Text>
                        <Text style={styles.colNumber}>{document.number || '-'}</Text>
                        <Text style={styles.colStatus}>{document.status || '-'}</Text>
                        <Text style={styles.colDate}>{document.issued_at ? moment(document.issued_at).format('DD/MM/YYYY') : '-'}</Text>
                        <Text style={styles.colUser}>{document.registered_by?.name || document.registeredBy?.name || '-'}</Text>
                    </View>
                ))}

                <View style={styles.footer}>
                    <View style={styles.footerCards}>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Documentos</Text>
                            <Text style={styles.footerCardValue}>{reportMeta?.documents_count || 0}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>NF-e</Text>
                            <Text style={styles.footerCardValue}>{reportMeta?.nfe_count || 0}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>NFS-e</Text>
                            <Text style={styles.footerCardValue}>{reportMeta?.nfse_count || 0}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Manual</Text>
                            <Text style={styles.footerCardValue}>{reportMeta?.manual_count || 0}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Integração anterior</Text>
                            <Text style={styles.footerCardValue}>{reportMeta?.integration_count || 0}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Erros</Text>
                            <Text style={styles.footerCardValue}>{reportMeta?.error_count || 0}</Text>
                        </View>
                    </View>
                </View>
            </Page>
        </Document>
    );
}
