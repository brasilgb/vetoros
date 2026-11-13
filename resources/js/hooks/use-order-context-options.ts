import { useEffect, useState } from 'react';

export type WarrantySourceOrderOption = {
    id: number;
    order_number: number;
    customer_id: number;
    equipment_id: number;
    model?: string | null;
    warranty_expires_at: string | null;
};

export type ActiveOrderOption = {
    id: number;
    order_number: number;
    customer_id: number;
    equipment_id: number | null;
    model?: string | null;
    service_status: number;
    updated_at: string;
};

type Options = {
    excludeOrderId?: number | null;
    currentSourceOrderId?: number | null;
    debounceMs?: number;
};

/**
 * OS de origem para garantia e OS em andamento do cliente/equipamento escolhidos, buscadas no
 * servidor só quando o cliente muda (com debounce e cancelamento da requisição anterior).
 * Substitui as listas de todas as OS do tenant que iam no carregamento do formulário.
 */
export function useOrderContextOptions(customerId: string | number | null | undefined, equipmentId: string | number | null | undefined, options: Options = {}) {
    const { excludeOrderId, currentSourceOrderId, debounceMs = 250 } = options;
    const [warrantySourceOrders, setWarrantySourceOrders] = useState<WarrantySourceOrderOption[]>([]);
    const [activeOrders, setActiveOrders] = useState<ActiveOrderOption[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const customer = String(customerId ?? '').trim();
        const equipment = String(equipmentId ?? '').trim();

        if (!customer) {
            setWarrantySourceOrders([]);
            setActiveOrders([]);
            setLoading(false);
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);
            try {
                const params: Record<string, string> = { customer_id: customer };
                if (equipment) params.equipment_id = equipment;
                if (excludeOrderId) params.exclude_order_id = String(excludeOrderId);
                if (currentSourceOrderId) params.current_source_order_id = String(currentSourceOrderId);

                const response = await fetch(route('app.orders.context-options', params), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                const payload = (await response.json()) as { warrantySourceOrders?: WarrantySourceOrderOption[]; activeOrders?: ActiveOrderOption[] };
                setWarrantySourceOrders(payload.warrantySourceOrders ?? []);
                setActiveOrders(payload.activeOrders ?? []);
            } catch (error) {
                if ((error as Error).name === 'AbortError') return;
                // Dado auxiliar: em falha o formulário segue funcionando, sem as sugestões.
                setWarrantySourceOrders([]);
                setActiveOrders([]);
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, debounceMs);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [customerId, equipmentId, excludeOrderId, currentSourceOrderId, debounceMs]);

    return { warrantySourceOrders, activeOrders, loading };
}
