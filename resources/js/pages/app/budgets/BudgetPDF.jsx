import { Document, Image, Page, StyleSheet, Text, View } from '@react-pdf/renderer';
import moment from 'moment';

const formatCurrency = (value) => {
    const num = parseFloat(value);
    if (isNaN(num)) return 'R$ 0,00';
    return `R$ ${num
        .toFixed(2)
        .replace('.', ',')
        .replace(/\B(?=(\d{3})+(?!\d))/g, '.')}`;
};

const formatWarranty = (value) => {
    if (value === undefined || value === null || value === '') return 'Não informada';

    const numeric = Number(value);
    if (!Number.isFinite(numeric) || numeric <= 0) return String(value);

    return `${numeric} ${numeric === 1 ? 'mês' : 'meses'}`;
};

const formatValidity = (value) => {
    if (value === undefined || value === null || value === '') return 'Não informada';

    const numeric = Number(value);
    if (!Number.isFinite(numeric) || numeric <= 0) return String(value);

    return `${numeric} ${numeric === 1 ? 'dia' : 'dias'}`;
};

const styles = StyleSheet.create({
    page: {
        padding: 30,
        fontFamily: 'Helvetica',
        fontSize: 10,
        lineHeight: 1.5,
        color: '#111827',
    },
    headerSection: {
        marginBottom: 18,
        paddingBottom: 12,
        borderBottomWidth: 1,
        borderBottomColor: '#d1d5db',
        borderBottomStyle: 'solid',
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'flex-start',
    },
    companyInfo: { width: '75%' },
    companyName: { fontSize: 18, fontWeight: 'bold', paddingBottom: 8, color: '#111827' },
    companyDetails: { fontSize: 9, color: '#4b5563', marginBottom: 2 },
    logo: { width: 58, height: 58, objectFit: 'contain' },
    budgetTitle: {
        fontSize: 15,
        fontWeight: 'bold',
        marginBottom: 14,
        textAlign: 'center',
        color: '#111827',
    },
    sectionContainer: {
        marginBottom: 15,
        padding: 10,
        backgroundColor: '#f9fafb',
        borderWidth: 1,
        borderColor: '#e5e7eb',
        borderStyle: 'solid',
        borderRadius: 4,
    },
    sectionTitle: {
        fontSize: 12,
        fontWeight: 'bold',
        marginBottom: 8,
        color: '#111827',
        paddingBottom: 4,
        borderBottomWidth: 1,
        borderBottomColor: '#e5e7eb',
        borderBottomStyle: 'solid',
    },
    row: {
        flexDirection: 'row',
        marginBottom: 5,
        justifyContent: 'space-between',
        gap: 12,
    },
    column: {
        width: '48%',
    },
    detailItem: {
        flexDirection: 'row',
        justifyContent: 'space-start',
        paddingVertical: 2,
        flexWrap: 'wrap',
    },
    label: {
        fontWeight: 'bold',
        color: '#4b5563',
    },
    value: {
        marginLeft: 5,
        color: '#111827',
    },
    obsBox: {
        borderWidth: 1,
        borderColor: '#d1d5db',
        borderStyle: 'solid',
        padding: 8,
        minHeight: 60,
        backgroundColor: '#fff',
    },
    totalGeral: {
        fontSize: 14,
        fontWeight: 'bold',
        color: '#991b1b',
        marginTop: 10,
        paddingTop: 8,
        borderTopWidth: 1,
        borderTopColor: '#d1d5db',
        borderTopStyle: 'solid',
        textAlign: 'right',
    },
    footerNote: {
        paddingTop: 8,
        fontSize: 8,
        color: '#4b5563',
    },
});

export const BudgetPDF = ({ company, budget }) => {
    const { companyname, cnpj, city, number, street, district, telephone, logo } = company ?? {};
    const { service, description, estimated_time, part_value, labor_value, total_value, warranty, validity, created_at, obs, equipment, model } =
        budget ?? {};

    const obsText = obs || 'Nenhuma observação informada.';

    return (
        <Document>
            <Page size="A4" style={styles.page}>
                <View style={styles.headerSection} fixed>
                    <View style={styles.companyInfo}>
                        <Text style={styles.companyName}>{companyname}</Text>
                        {cnpj ? <Text style={styles.companyDetails}>CNPJ: {cnpj}</Text> : null}
                        {telephone ? <Text style={styles.companyDetails}>Tel: {telephone}</Text> : null}
                        {street || number || district || city ? (
                            <Text style={styles.companyDetails}>{[street, number, district, city].filter(Boolean).join(', ')}</Text>
                        ) : null}
                    </View>

                    {logo ? <Image style={styles.logo} source={`/storage/logos/${logo}`} /> : null}
                </View>

                <Text style={styles.budgetTitle}>DETALHES DO ORÇAMENTO DE SERVIÇO</Text>

                <View style={styles.sectionContainer}>
                    <Text style={styles.sectionTitle}>Equipamento e serviço</Text>

                    <View style={styles.detailItem}>
                        <Text style={styles.label}>Equipamento:</Text>
                        <Text style={styles.value}>{equipment?.equipment || 'Não informado'}</Text>
                    </View>
                    <View style={styles.detailItem}>
                        <Text style={styles.label}>Modelo:</Text>
                        <Text style={styles.value}>{model || 'Não informado'}</Text>
                    </View>

                    <View style={styles.detailItem}>
                        <Text style={styles.label}>Serviço:</Text>
                        <Text style={styles.value}>{service}</Text>
                    </View>
                    <View style={styles.detailItem}>
                        <Text style={styles.label}>Descrição:</Text>
                        <Text style={styles.value}>{description || 'Não informada'}</Text>
                    </View>
                    <View style={styles.detailItem}>
                        <Text style={styles.label}>Tempo estimado:</Text>
                        <Text style={styles.value}>{estimated_time || 'Não informado'}</Text>
                    </View>
                </View>

                <View style={styles.sectionContainer}>
                    <Text style={styles.sectionTitle}>Valores e Condições</Text>

                    <View style={styles.row}>
                        <View style={styles.column}>
                            <View style={styles.detailItem}>
                                <Text style={styles.label}>Valor Mão de Obra:</Text>
                                <Text style={styles.value}>{formatCurrency(labor_value)}</Text>
                            </View>
                            <View style={styles.detailItem}>
                                <Text style={styles.label}>Valor Peças:</Text>
                                <Text style={styles.value}>{formatCurrency(part_value)}</Text>
                            </View>
                        </View>

                        <View style={styles.column}>
                            <View style={styles.detailItem}>
                                <Text style={styles.label}>Garantia:</Text>
                                <Text style={styles.value}>{formatWarranty(warranty)}</Text>
                            </View>
                            <View style={styles.detailItem}>
                                <Text style={styles.label}>Validade:</Text>
                                <Text style={styles.value}>{formatValidity(validity)}</Text>
                            </View>

                            <View style={[styles.detailItem, styles.totalGeral]}>
                                <Text>TOTAL GERAL:</Text>
                                <Text>{formatCurrency(total_value)}</Text>
                            </View>
                        </View>
                    </View>
                </View>

                <View style={styles.sectionContainer}>
                    <Text style={styles.sectionTitle}>Observações</Text>
                    <View style={styles.obsBox}>
                        <Text>{obsText}</Text>
                    </View>
                </View>

                <View style={styles.footerNote}>
                    <Text>
                        Orçamento gerado em {moment(created_at).format('DD/MM/YYYY')}. Este orçamento é válido por {formatValidity(validity)}.
                    </Text>
                </View>
            </Page>
        </Document>
    );
};
