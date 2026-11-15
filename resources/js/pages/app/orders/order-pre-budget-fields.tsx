import { Label } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

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
                <div
                    className={cn(
                        'focus-within:border-ring focus-within:ring-ring/50 border-input dark:bg-input/30 flex h-11 items-center rounded-md border px-3 shadow-xs focus-within:ring-[3px]',
                        errors.budget_value && 'border-destructive',
                    )}
                >
                    <span className="text-muted-foreground mr-2 shrink-0 text-sm">R$</span>
                    <MoneyInput
                        id="budget_value"
                        showPrefix={false}
                        value={data.budget_value ?? '0'}
                        onValueChange={(value) => setData('budget_value', value)}
                        className="h-full border-0 px-0 shadow-none focus-visible:ring-0 md:text-sm"
                    />
                </div>
                {errors.budget_value && <div className="text-sm text-red-500">{errors.budget_value}</div>}
            </div>
        </>
    );
}
