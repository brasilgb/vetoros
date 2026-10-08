import { Button } from '@/components/ui/button';
import { useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import { ChangeEvent, FormEvent } from 'react';

interface Props {
    isOpen: boolean;
    onClose: () => void;
}

interface FormData {
    arquivo: File | null;
}

export default function ImportCustomersModal({ isOpen, onClose }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm<FormData>({
        arquivo: null,
    });

    if (!isOpen) return null;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('app.import.customer'), {
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    const baixarExemplo = () => {
        // Começando direto pelos dados que o usuário realmente tem
        const headers =
            'name;cpfcnpj;birth;email;zipcode;state;city;district;street;complement;number;phone;contactname;whatsapp;contactphone;observations';

        // Removi os espaços vazios do início da linha de exemplo
        const exemplo =
            '\nJoao da Silva;12345678900;1990-05-15;joao@email.com;01001-000;SP;Sao Paulo;Se;Praca da Se;Edificio;100;(11) 99999-8888;Maria Silva;5511988887777;(11) 99999-8888;Cliente para teste de importacao';

        const csvContent = headers + exemplo;

        const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);

        const link = document.createElement('a');
        link.href = url;
        link.download = 'customer_model.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div className="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
                <div className="mb-4 flex items-center justify-between">
                    <h3 className="text-secondary text-lg font-bold">Importar CSV de Clientes</h3>
                    <Button variant={'link'} onClick={onClose}>
                        <X className="h-4 w-4" />
                    </Button>
                </div>

                <form onSubmit={submit}>
                    <div className="mb-4">
                        <p className="pb-1 text-sm text-red-400 italic">Delimitadores aceitos ( vírgula ,) ( ponto e vírgula ; ) ( pipe | )</p>
                        <label className="mb-2 block text-sm font-medium text-gray-700">Selecione o arquivo .csv</label>
                        <input
                            type="file"
                            accept=".csv"
                            onChange={(e: ChangeEvent<HTMLInputElement>) => {
                                if (e.target.files) setData('arquivo', e.target.files[0]);
                            }}
                            className="block w-full rounded-md border border-gray-300 p-2 text-sm text-gray-800"
                        />
                        {errors.arquivo && <p className="mt-1 text-xs text-red-500">{errors.arquivo}</p>}
                    </div>

                    <div className="flex justify-end gap-2">
                        <Button variant={'destructive'} type="button" onClick={baixarExemplo}>
                            Baixar modelo .csv
                        </Button>
                        <Button variant={'secondary'} type="button" onClick={onClose} className="px-4 py-2 text-sm">
                            Cancelar
                        </Button>
                        <Button variant={'default'} type="submit" disabled={processing || !data.arquivo} className="px-4 py-2 text-sm">
                            {processing ? 'Importando...' : 'Confirmar Importação'}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}
