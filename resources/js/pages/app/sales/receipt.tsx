import { formatBRL } from '@/lib/money';
import { maskCnpj } from '@/Utils/mask';
import { usePage } from '@inertiajs/react';
import moment from 'moment';
import { forwardRef } from 'react';

type Props = {
    paper: '58mm' | '80mm';
    items: any;
    total: any;
    customer: any;
    sale: any;
};

const widths = {
    '58mm': '219px',
    '80mm': '302px',
};

const Receipt = forwardRef<HTMLDivElement, Props>(({ paper, items, total, customer, sale }, ref) => {
    const { auth } = usePage().props as any;
    const companyData = auth?.user?.tenant;

    return (
        <div ref={ref} id="print-area" className="mx-auto bg-white font-mono text-black" style={{ width: widths[paper] }}>
            <div className="p-4 text-[12px]">
                <div className="text-center font-bold uppercase">{companyData?.company}</div>

                <div className="text-center text-[10px] leading-tight">
                    CNPJ: {maskCnpj(companyData?.cnpj)}
                    <br />
                    {companyData?.street}, {companyData?.number} - {companyData?.district}
                    <br />
                    {companyData?.city} - {companyData?.state}
                    <br />
                    {companyData?.telephone}
                </div>

                <div className="my-2 border-t border-dashed border-black" />

                <div className="text-[10px]">
                    Venda Nº {sale?.id}
                    <br />
                    Data: {moment(sale?.date).format('DD/MM/YYYY HH:mm')}
                    <br />
                    Cliente: {customer}
                    <br />
                    Pagamento: {sale?.payment_method || 'Não informado'}
                </div>

                <div className="my-2 border-t border-dashed border-black" />

                {items.map((item: any, i: number) => (
                    <div key={i} className="mt-1 text-[10px]">
                        {item.name}
                        <div className="flex justify-between pl-2">
                            <span>
                                {item.selected_quantity} x {formatBRL(item.sale_price)}
                            </span>
                            <span>{formatBRL(item.selected_quantity * item.sale_price)}</span>
                        </div>
                    </div>
                ))}

                <div className="my-2 border-t border-dashed border-black" />

                <div className="flex justify-between text-[14px] font-bold">
                    <span>TOTAL</span>
                    <span>R$ {total}</span>
                </div>

                <div className="mt-2 text-center text-[10px]">Documento sem valor fiscal</div>
            </div>
        </div>
    );
});

Receipt.displayName = 'Receipt';

export default Receipt;
