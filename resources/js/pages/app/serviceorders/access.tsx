import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm } from '@inertiajs/react';
import { KeyRound, ShieldCheck } from 'lucide-react';

export default function PublicOrderAccess({ token, orderNumber }: { token: string; orderNumber: number }) {
    const form = useForm({ key: '' });

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4">
            <Head title={`Acesso à OS #${orderNumber}`} />
            <div className="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-7 text-slate-900 shadow-xl">
                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-white">
                    <ShieldCheck />
                </div>
                <h1 className="mt-5 text-2xl font-semibold">Acesso à OS #{orderNumber}</h1>
                <p className="mt-2 text-sm text-slate-600">Informe a chave de 8 caracteres enviada pela assistência.</p>
                <form
                    className="mt-6 space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(route('os.access', token));
                    }}
                >
                    <div className="space-y-2">
                        <Label htmlFor="key">Chave de acesso</Label>
                        <Input
                            id="key"
                            autoFocus
                            maxLength={8}
                            value={form.data.key}
                            onChange={(event) => form.setData('key', event.target.value.toUpperCase())}
                            className="text-center font-mono text-lg tracking-[0.3em] uppercase"
                        />
                        <InputError message={form.errors.key} />
                    </div>
                    <Button className="w-full" disabled={form.processing}>
                        <KeyRound className="h-4 w-4" />
                        {form.processing ? 'Validando...' : 'Acessar ordem'}
                    </Button>
                </form>
            </div>
        </div>
    );
}
