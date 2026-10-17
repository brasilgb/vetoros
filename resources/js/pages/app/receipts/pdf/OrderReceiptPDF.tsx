import { formatBRL } from '@/lib/money';
import { Document, Image, Page, StyleSheet, Text, View } from '@react-pdf/renderer';

type Props = {
    order: any;
    company: any;
    type: string;
    receipt: any;
    checklist: any;
};

const styles = StyleSheet.create({
    page: {
        padding: 28,
        fontSize: 10,
        color: '#111827',
    },
    header: {
        flexDirection: 'row',
        alignItems: 'center',
        gap: 12,
        marginBottom: 12,
        paddingBottom: 10,
        borderBottomWidth: 1.2,
        borderBottomColor: '#d1d5db',
    },
    logo: {
        width: 48,
        height: 48,
        objectFit: 'contain',
    },
    headerText: {
        flex: 1,
    },
    title: {
        fontSize: 15,
        fontWeight: 700,
        marginBottom: 4,
    },
    subtitle: {
        fontSize: 9,
        color: '#4b5563',
        lineHeight: 1.35,
    },
    osBox: {
        width: 92,
        padding: 8,
        borderWidth: 1,
        borderColor: '#d1d5db',
        borderRadius: 4,
        textAlign: 'right',
    },
    osLabel: {
        fontSize: 7,
        color: '#6b7280',
        textTransform: 'uppercase',
        marginBottom: 3,
    },
    osNumber: {
        fontSize: 14,
        fontWeight: 700,
    },
    section: {
        marginTop: 8,
        borderWidth: 1,
        borderColor: '#d1d5db',
        borderRadius: 4,
    },
    sectionTitle: {
        fontSize: 10,
        fontWeight: 700,
        padding: 6,
        borderBottomWidth: 1,
        borderBottomColor: '#d1d5db',
        backgroundColor: '#f9fafb',
    },
    sectionBody: {
        padding: 8,
    },
    row: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        gap: 8,
    },
    col: {
        width: '49%',
    },
    text: {
        marginBottom: 3,
        lineHeight: 1.35,
    },
    warrantyText: {
        fontSize: 8.5,
        lineHeight: 1.15,
        textAlign: 'justify',
    },
    mutedLabel: {
        color: '#6b7280',
    },
    totalRow: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        marginTop: 8,
        paddingTop: 8,
        borderTopWidth: 1,
        borderTopColor: '#d1d5db',
    },
    checklistGrid: {
        flexDirection: 'row',
        flexWrap: 'wrap',
        gap: 8,
    },
    checklistItem: {
        width: '48%',
        flexDirection: 'row',
        alignItems: 'center',
        gap: 5,
        marginBottom: 4,
    },
    checkbox: {
        width: 9,
        height: 9,
        borderWidth: 1,
        borderColor: '#111827',
    },
    tracking: {
        marginTop: 10,
        padding: 8,
        borderWidth: 1,
        borderColor: '#d1d5db',
        borderRadius: 4,
        fontSize: 9,
    },
    footer: {
        marginTop: 14,
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'flex-end',
        gap: 24,
    },
    footerDate: {
        flex: 1,
        textAlign: 'center',
        fontSize: 9,
        color: '#4b5563',
    },
    signature: {
        flex: 1,
        paddingTop: 7,
        borderTopWidth: 1,
        borderTopColor: '#111827',
        textAlign: 'center',
        fontSize: 9,
    },
    signatureSigned: {
        flex: 1,
        textAlign: 'center',
        fontSize: 9,
    },
    signatureImage: {
        height: 34,
        width: 110,
        objectFit: 'contain',
        alignSelf: 'center',
        marginBottom: 2,
    },
});

function titleByType(type: string) {
    if (type === 'oraberta') return 'Recibo de Entrada de Equipamento';
    if (type === 'orentrega') return 'Recibo de Entrega de Equipamento';
    if (type === 'ororcamento') return 'Orçamento ao Cliente';
    if (type === 'orchecklist') return 'Checklist para Conferência do Equipamento';
    return 'Recibo';
}

function textByType(type: string, receipt: any) {
    if (type === 'oraberta') return receipt?.receivingequipment ?? '';
    if (type === 'orentrega') return receipt?.equipmentdelivery ?? '';
    if (type === 'ororcamento') return receipt?.budgetissuance ?? '';
    if (type === 'orchecklist') return 'Checklist do equipamento para conferência.';
    return '';
}

const normalizePlaceholderKey = (key: string) =>
    key
        .trim()
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, '_')
        .replace(/-/g, '_');

function renderReceiptTemplate(template: string, order: any) {
    const values = {
        cliente: order?.customer?.name ?? '',
        cpf_cnpj: order?.customer?.cpfcnpj ?? '',
        defeito: order?.defect ?? '',
        equipamento: order?.equipment?.equipment ?? '',
        modelo: order?.model ?? '',
        ordem: String(order?.order_number ?? ''),
        prazo: order?.delivery_forecast ?? '',
        valor_orcamento: formatBRL(order?.budget_value),
    };

    return template.replace(/\{\{\s*([^}]+?)\s*\}\}/g, (_, key) => {
        const normalizedKey = normalizePlaceholderKey(String(key));

        if (normalizedKey in values) {
            return values[normalizedKey as keyof typeof values];
        }

        return '';
    });
}

function partQuantity(part: any) {
    return Number(part?.pivot?.quantity ?? part?.quantity ?? 1) || 1;
}

function partTotal(part: any) {
    return Number(part?.sale_price ?? 0) * partQuantity(part);
}

function partsDescription(orderParts: any[], fallback: any) {
    if (orderParts.length === 0) {
        return fallback || '-';
    }

    return orderParts
        .map((part: any) => {
            const quantity = partQuantity(part);
            const name = part?.name ?? 'Peça';

            return quantity > 1 ? `${quantity}x ${name}` : name;
        })
        .join(', ');
}

function logoSource(company: any) {
    // `logo_url` vem resolvido pelo backend (já validando se o arquivo existe),
    // evitando que o @react-pdf/renderer trave tentando carregar uma imagem quebrada.
    if (company?.logo_url) return company.logo_url;
    return company?.logo ? `/storage/logos/${company.logo}` : '/images/default.png';
}

export default function OrderReceiptPDF({ order, company, type, receipt, checklist }: Props) {
    const checklistItems = checklist?.checklist
        ? String(checklist.checklist)
              .split(',')
              .map((item: string) => item.trim())
              .filter(Boolean)
        : [];
    const orderParts = order?.order_parts ?? order?.orderParts ?? [];
    const partsValue = orderParts.length > 0 ? orderParts.reduce((total: number, part: any) => total + partTotal(part), 0) : order?.parts_value;
    const receiptText = renderReceiptTemplate(textByType(type, receipt), order);

    return (
        <Document title={`${titleByType(type)} - OS ${order?.order_number ?? ''}`}>
            <Page size="A4" style={styles.page}>
                <View style={styles.header}>
                    <Image style={styles.logo} source={logoSource(company)} />
                    <View style={styles.headerText}>
                        <Text style={styles.title}>{company?.companyname ?? '-'}</Text>
                        <Text style={styles.subtitle}>CNPJ: {company?.cnpj ?? '-'}</Text>
                        <Text style={styles.subtitle}>
                            {company?.street ?? '-'}, {company?.number ?? '-'} - {company?.district ?? '-'}
                        </Text>
                        <Text style={styles.subtitle}>
                            {company?.city ?? '-'} {company?.state ? `- ${company.state}` : ''} | {company?.telephone ?? '-'}
                        </Text>
                    </View>
                    <View style={styles.osBox}>
                        <Text style={styles.osLabel}>Ordem de Serviço</Text>
                        <Text style={styles.osNumber}>#{order?.order_number ?? '-'}</Text>
                    </View>
                </View>

                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>{titleByType(type)}</Text>
                    <View style={styles.sectionBody}>
                        <View style={styles.row}>
                            <View style={styles.col}>
                                <Text style={styles.text}>Cliente: {order?.customer?.name ?? '-'}</Text>
                                <Text style={styles.text}>CPF/CNPJ: {order?.customer?.cpfcnpj ?? '-'}</Text>
                            </View>
                            <View style={styles.col}>
                                <Text style={styles.text}>Telefone: {order?.customer?.phone ?? '-'}</Text>
                                <Text style={styles.text}>
                                    Endereço: {order?.customer?.street ?? '-'}, {order?.customer?.number ?? '-'} - {order?.customer?.district ?? '-'}
                                </Text>
                            </View>
                        </View>
                    </View>
                </View>

                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Informações do Equipamento</Text>
                    <View style={styles.sectionBody}>
                        <View style={styles.row}>
                            <View style={styles.col}>
                                <Text style={styles.text}>Equipamento: {order?.equipment?.equipment ?? '-'}</Text>
                                <Text style={styles.text}>Modelo: {order?.model ?? '-'}</Text>
                            </View>
                            <View style={styles.col}>
                                <Text style={styles.text}>Defeito: {order?.defect ?? '-'}</Text>
                                <Text style={styles.text}>Valor previsto: {formatBRL(order?.budget_value)}</Text>
                            </View>
                        </View>
                        <Text style={styles.text}>Pré-orçamento: {order?.budget_description ?? '-'}</Text>
                    </View>
                </View>

                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Garantias e Observações</Text>
                    <View style={styles.sectionBody}>
                        <Text style={styles.warrantyText}>{receiptText || '-'}</Text>
                    </View>
                </View>

                {type === 'ororcamento' && (
                    <View style={styles.section}>
                        <Text style={styles.sectionTitle}>Orçamento Gerado</Text>
                        <View style={styles.sectionBody}>
                            <Text style={styles.text}>{order?.budget_description ?? '-'}</Text>
                            <View style={styles.totalRow}>
                                <Text>Valor</Text>
                                <Text>{formatBRL(order?.budget_value)}</Text>
                            </View>
                        </View>
                    </View>
                )}

                {type === 'orentrega' && (
                    <View style={styles.section}>
                        <Text style={styles.sectionTitle}>Serviço Prestado</Text>
                        <View style={styles.sectionBody}>
                            <Text style={styles.text}>{order?.services_performed ?? '-'}</Text>
                            <Text style={styles.text}>Peças adicionadas: {partsDescription(orderParts, order?.parts)}</Text>
                            <View style={styles.totalRow}>
                                <Text>Peças: {formatBRL(partsValue)}</Text>
                                <Text>Serviço: {formatBRL(order?.service_value)}</Text>
                                <Text>Total: {formatBRL(order?.service_cost)}</Text>
                            </View>
                        </View>
                    </View>
                )}

                {type === 'orchecklist' && (
                    <View style={styles.section}>
                        <Text style={styles.sectionTitle}>Checklist de Conferência</Text>
                        <View style={styles.sectionBody}>
                            <View style={styles.checklistGrid}>
                                {(checklistItems.length > 0 ? checklistItems : ['Sem itens cadastrados']).map((item: string) => (
                                    <View key={item} style={styles.checklistItem}>
                                        <View style={styles.checkbox} />
                                        <Text>{item}</Text>
                                    </View>
                                ))}
                            </View>
                        </View>
                    </View>
                )}

                {type === 'oraberta' && (
                    <View style={styles.tracking}>
                        <Text>Acompanhe o status da ordem de serviço em https://vetoros.com.br/os/{order?.tracking_token ?? ''}</Text>
                    </View>
                )}

                <View style={styles.footer}>
                    <Text style={styles.footerDate}>
                        {company?.city ?? '-'}, {new Date().toLocaleDateString('pt-BR')}
                    </Text>
                    {type === 'oraberta' && order?.customer_signature_url ? (
                        <View style={styles.signatureSigned}>
                            <Image style={styles.signatureImage} source={order.customer_signature_url} />
                            <Text>Assinatura do Cliente</Text>
                        </View>
                    ) : (
                        <Text style={styles.signature}>Assinatura do Cliente</Text>
                    )}
                </View>
            </Page>
        </Document>
    );
}
