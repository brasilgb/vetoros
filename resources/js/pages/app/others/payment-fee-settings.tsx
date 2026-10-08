import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { Save } from 'lucide-react';
import type { FormEvent } from 'react';

type PaymentFee = { payment_method: string; fee_percentage: string | null; fee_fixed_amount: string | null };

const METHODS: { value: string; label: string }[] = [
    { value: 'cartao', label: 'Cartão' },
    { value: 'pix', label: 'Pix' },
    { value: 'boleto', label: 'Boleto' },
    { value: 'transferencia', label: 'Transferência' },
    { value: 'dinheiro', label: 'Dinheiro' },
];

/**
 * Taxa da operadora por meio de pagamento. Campo vazio = taxa desconhecida (o pagamento
 * fica sem taxa); zero = sem taxa. Cada pagamento congela a taxa vigente no registro.
 */
export default function PaymentFeeSettings({ paymentFees, disabled }: { paymentFees: PaymentFee[]; disabled: boolean }) {
    const current = new Map((paymentFees ?? []).map((fee) => [fee.payment_method, fee]));
    const { data, setData, put, processing, errors } = useForm({
        fees: METHODS.map((method) => ({
            payment_method: method.value,
            fee_percentage: current.get(method.value)?.fee_percentage ?? '',
            fee_fixed_amount: current.get(method.value)?.fee_fixed_amount ?? '',
        })),
    });

    const update = (index: number, field: 'fee_percentage' | 'fee_fixed_amount', value: string) => {
        setData(
            'fees',
            data.fees.map((fee, i) => (i === index ? { ...fee, [field]: value } : fee)),
        );
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // Campo vazio chega como null (ConvertEmptyStringsToNull): remove a configuração do meio.
        put(route('app.payment-fee-settings.update'), { preserveScroll: true });
    };

    return (
        <Card className="mt-6">
            <CardTitle className="border-b px-6 py-4">Taxas dos meios de pagamento</CardTitle>
            <CardContent className="space-y-4 pt-4">
                <p className="text-muted-foreground text-sm">
                    Usadas para calcular quanto entra líquido em cada pagamento. Deixe em branco quando não souber a taxa e informe 0 quando não
                    houver taxa. Alterações valem só para os próximos pagamentos.
                </p>
                <form onSubmit={submit} className="space-y-3">
                    <div className="text-muted-foreground hidden grid-cols-3 gap-2 text-xs font-medium sm:grid" aria-hidden="true">
                        <span>Meio de pagamento</span>
                        <span>Percentual (%)</span>
                        <span>Valor fixo por pagamento (R$)</span>
                    </div>
                    {data.fees.map((fee, index) => (
                        <div key={fee.payment_method} className="grid grid-cols-1 items-start gap-2 sm:grid-cols-3">
                            <span className="pt-2 text-sm font-medium">{METHODS[index].label}</span>
                            <div>
                                <Input
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.001"
                                    placeholder="% (ex.: 2,99)"
                                    aria-label={`Percentual ${METHODS[index].label}`}
                                    value={fee.fee_percentage ?? ''}
                                    onChange={(e) => update(index, 'fee_percentage', e.target.value)}
                                    disabled={disabled}
                                />
                                <InputError message={(errors as Record<string, string>)[`fees.${index}.fee_percentage`]} />
                            </div>
                            <div>
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    placeholder="Valor fixo (R$)"
                                    aria-label={`Valor fixo ${METHODS[index].label}`}
                                    value={fee.fee_fixed_amount ?? ''}
                                    onChange={(e) => update(index, 'fee_fixed_amount', e.target.value)}
                                    disabled={disabled}
                                />
                                <InputError message={(errors as Record<string, string>)[`fees.${index}.fee_fixed_amount`]} />
                            </div>
                        </div>
                    ))}
                    <div className="flex justify-end">
                        <Button type="submit" disabled={processing || disabled}>
                            <Save />
                            Salvar taxas
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}
