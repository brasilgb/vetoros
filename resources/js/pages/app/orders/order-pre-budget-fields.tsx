import { Label } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { Textarea } from '@/components/ui/textarea';

type OrderPreBudgetFieldsProps = {
    data: {
        budget_description: string;
        budget_value: string | number;
    };
    setData: (key: 'budget_description' | 'budget_value', value: string) => void;
    errors: Partial<Record<'budget_description' | 'budget_value', string>>;
    descriptionLabel?: string;
    valueLabel?: string;
};

/**
 * Campos de pré-orçamento (descrição + valor) preenchidos no mesmo atendimento
 * em que a OS é aberta. Extraído para cá porque a mesma dupla de campos e a
 * mesma máscara monetária se repetiam em create-order.tsx e edit-order.tsx.
 */
export default function OrderPreBudgetFields({
    data,
    setData,
    errors,
    descriptionLabel = 'Descrição pré-orçamento',
    valueLabel = 'Valor pré-orçamento',
}: OrderPreBudgetFieldsProps) {
    return (
        <>
            <div className="grid gap-2 md:col-span-2">
                <Label htmlFor="budget_description">{descriptionLabel}</Label>
                <Textarea id="budget_description" value={data.budget_description} onChange={(e) => setData('budget_description', e.target.value)} />
                {errors.budget_description && <div className="text-sm text-red-500">{errors.budget_description}</div>}
            </div>

            <div className="grid gap-2">
                <Label htmlFor="budget_value">{valueLabel}</Label>
                <MoneyInput id="budget_value" value={data.budget_value ?? '0'} onValueChange={(value) => setData('budget_value', value)} />
                {errors.budget_value && <div className="text-sm text-red-500">{errors.budget_value}</div>}
            </div>
        </>
    );
}
