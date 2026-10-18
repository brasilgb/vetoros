import { currencyFormatter } from '@/Utils/currency-formatter';
import { ORDER_STATUS, ORDER_STATUSES_COMPLETED } from '@/Utils/order-status';
import { Document, Image, Page, StyleSheet, Text, View } from '@react-pdf/renderer';
import moment from 'moment';

moment.locale('pt-br');

type WarrantyRankingItem = {
    label: string;
    total: number;
};

const styles = StyleSheet.create({
    page: {
        padding: 12,
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
        marginBottom: 14,
        color: '#666',
    },
    severityBox: {
        borderRadius: 4,
        paddingVertical: 8,
        paddingHorizontal: 10,
        marginBottom: 14,
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

    section: {
        marginBottom: 14,
        border: '1px solid #ddd',
        borderRadius: 4,
        overflow: 'hidden',
        backgroundColor: '#FFF',
    },

    sectionHeader: {
        backgroundColor: '#eeeded',
        padding: 6,
        fontWeight: 'bold',
        borderBottom: '1px solid #999',
    },

    row: {
        flexDirection: 'row',
        borderBottom: '0.5px solid #DDD',
        paddingVertical: 5,
        paddingHorizontal: 6,
    },

    col: {
        flex: 1,
    },

    colRight: {
        width: '25%',
        textAlign: 'right',
    },

    footer: {
        marginTop: 16,
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

export default function OrderStatisticsPDF({ data, reportMeta, dateRange, company }: any) {
    const period =
        dateRange?.from && dateRange?.to
            ? `${moment(dateRange.from).format('DD/MM/YYYY')} - ${moment(dateRange.to).format('DD/MM/YYYY')}`
            : 'Período não informado';

    const stats = {
        total: data.length,
        abertas: data.filter((o: any) => o.service_status === ORDER_STATUS.OPEN).length,
        orcGerados: data.filter((o: any) => o.service_status === ORDER_STATUS.BUDGET_GENERATED).length,
        orcAprovados: data.filter((o: any) => o.service_status === ORDER_STATUS.BUDGET_APPROVED).length,
        reparo: data.filter((o: any) => o.service_status === ORDER_STATUS.REPAIR_IN_PROGRESS).length,
        concluidos: data.filter((o: any) => ORDER_STATUSES_COMPLETED.includes(o.service_status)).length,
        entregues: data.filter((o: any) => o.service_status === ORDER_STATUS.DELIVERED).length,
        retornosGarantia: data.filter((o: any) => Boolean(o.is_warranty_return)).length,
    };
    const warrantyReturnRate = Number(
        reportMeta?.warranty_return_rate ?? (stats.total > 0 ? ((stats.retornosGarantia / stats.total) * 100).toFixed(1) : '0.0'),
    );
    const warrantyThreshold = Number(reportMeta?.warranty_return_threshold ?? 10);
    const warrantyAlert = Boolean(reportMeta?.warranty_return_alert ?? false);
    const warrantySeverity = warrantyReturnRate <= 5 ? 'Saudável' : warrantyReturnRate <= warrantyThreshold ? 'Atenção' : 'Crítico';
    const severityColors =
        warrantySeverity === 'Crítico'
            ? { backgroundColor: '#FEF3C7', borderColor: '#F59E0B', color: '#92400E' }
            : warrantySeverity === 'Atenção'
              ? { backgroundColor: '#EFF6FF', borderColor: '#60A5FA', color: '#1D4ED8' }
              : { backgroundColor: '#ECFDF5', borderColor: '#34D399', color: '#065F46' };
    const warrantyOrders = data.filter((o: any) => Boolean(o.is_warranty_return));
    const topWarrantyEquipments: WarrantyRankingItem[] = Object.entries(
        warrantyOrders.reduce((acc: Record<string, number>, order: any) => {
            const key = order.equipment?.equipment || 'Equipamento não informado';
            acc[key] = (acc[key] ?? 0) + 1;
            return acc;
        }, {}),
    )
        .map(([label, total]) => ({ label, total: Number(total) }))
        .sort((a, b) => b.total - a.total)
        .slice(0, 5);
    const topWarrantyDefects: WarrantyRankingItem[] = Object.entries(
        warrantyOrders.reduce((acc: Record<string, number>, order: any) => {
            const key = String(order.defect || 'Defeito não informado').trim() || 'Defeito não informado';
            acc[key] = (acc[key] ?? 0) + 1;
            return acc;
        }, {}),
    )
        .map(([label, total]) => ({ label, total: Number(total) }))
        .sort((a, b) => b.total - a.total)
        .slice(0, 5);

    const totalServicos = data.reduce((acc: number, o: any) => acc + Number(o.service_value || 0), 0);

    const totalPecas = data.reduce((acc: number, o: any) => acc + Number(o.parts_value || 0), 0);

    const totalGeral = totalServicos + totalPecas;

    return (
        <Document>
            <Page size="A4" style={styles.page}>
                <View style={styles.logoPlaceholder}>
                    <Image source={`${company?.logo ? `/storage/logos/${company?.logo}` : '/images/default.png'}`} />
                </View>
                <Text style={styles.title}>{company?.companyname}</Text>
                <Text style={styles.subtitle}>Resumo Geral de Ordens de Serviço</Text>

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

                {/* RESUMO GERAL */}
                <View style={styles.section}>
                    <Text style={styles.sectionHeader}>Resumo Geral</Text>

                    <View style={styles.row}>
                        <Text style={styles.col}>Total de ordens no período</Text>
                        <Text style={styles.colRight}>{stats.total}</Text>
                    </View>

                    <View style={styles.row}>
                        <Text style={styles.col}>Ordens abertas</Text>
                        <Text style={styles.colRight}>{stats.abertas}</Text>
                    </View>

                    <View style={styles.row}>
                        <Text style={styles.col}>Orçamentos gerados</Text>
                        <Text style={styles.colRight}>{stats.orcGerados}</Text>
                    </View>

                    <View style={styles.row}>
                        <Text style={styles.col}>Orçamentos aprovados</Text>
                        <Text style={styles.colRight}>{stats.orcAprovados}</Text>
                    </View>

                    <View style={styles.row}>
                        <Text style={styles.col}>Equipamentos em reparo</Text>
                        <Text style={styles.colRight}>{stats.reparo}</Text>
                    </View>

                    <View style={styles.row}>
                        <Text style={styles.col}>Serviços concluídos</Text>
                        <Text style={styles.colRight}>{stats.concluidos}</Text>
                    </View>

                    <View style={styles.row}>
                        <Text style={styles.col}>Equipamentos entregues</Text>
                        <Text style={styles.colRight}>{stats.entregues}</Text>
                    </View>

                    <View style={styles.row}>
                        <Text style={styles.col}>Retornos em garantia</Text>
                        <Text style={styles.colRight}>{stats.retornosGarantia}</Text>
                    </View>
                </View>

                {/* FATURAMENTO */}
                <View style={styles.section}>
                    <Text style={styles.sectionHeader}>Faturamento</Text>

                    <View style={styles.row}>
                        <Text style={styles.col}>Total em serviços</Text>
                        <Text style={styles.colRight}>{currencyFormatter(totalServicos)}</Text>
                    </View>

                    <View style={styles.row}>
                        <Text style={styles.col}>Total em peças</Text>
                        <Text style={styles.colRight}>{currencyFormatter(totalPecas)}</Text>
                    </View>

                    <View style={styles.row}>
                        <Text style={styles.col}>Faturamento total</Text>
                        <Text style={styles.colRight}>{currencyFormatter(totalGeral)}</Text>
                    </View>
                </View>

                {warrantyOrders.length > 0 && (
                    <View style={styles.section}>
                        <Text style={styles.sectionHeader}>Análise de Retorno em Garantia</Text>

                        <View style={styles.row}>
                            <Text style={styles.col}>Taxa de retorno em garantia</Text>
                            <Text style={styles.colRight}>{warrantyReturnRate}%</Text>
                        </View>

                        {topWarrantyEquipments.map((item) => (
                            <View key={`eq-${item.label}`} style={styles.row}>
                                <Text style={styles.col}>Equipamento: {item.label}</Text>
                                <Text style={styles.colRight}>{item.total}</Text>
                            </View>
                        ))}

                        {topWarrantyDefects.map((item) => (
                            <View key={`df-${item.label}`} style={styles.row}>
                                <Text style={styles.col}>Defeito: {item.label}</Text>
                                <Text style={styles.colRight}>{item.total}</Text>
                            </View>
                        ))}
                    </View>
                )}

                {/* Rodapé */}
                <View style={styles.footer}>
                    <View style={styles.footerCards}>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Ordens Analisadas</Text>
                            <Text style={styles.footerCardValue}>{stats.total}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Concluídas</Text>
                            <Text style={styles.footerCardValue}>{stats.concluidos}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Entregues</Text>
                            <Text style={styles.footerCardValue}>{stats.entregues}</Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Retorno em Garantia</Text>
                            <Text style={styles.footerCardValue}>
                                {stats.retornosGarantia} ({warrantyReturnRate}%)
                            </Text>
                        </View>
                        <View style={styles.footerCard}>
                            <Text style={styles.footerCardLabel}>Faturamento Total</Text>
                            <Text style={styles.footerCardValue}>{currencyFormatter(totalGeral)}</Text>
                        </View>
                    </View>
                </View>
            </Page>
        </Document>
    );
}
