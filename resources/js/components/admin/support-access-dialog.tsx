import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { maskCnpj } from '@/Utils/mask';
import { useForm } from '@inertiajs/react';
import { LogIn } from 'lucide-react';
import { useEffect } from 'react';

export type SupportTarget = { id: number; name: string; company: string | null; cnpj: string | null; computed_subscription_label: string };

/**
 * "Acessar painel" (Modo Suporte, VETOR-STABILIZE-03): confirma a empresa, a situação e o ambiente, e
 * exige motivo e a senha do próprio RootAdmin. A senha do cliente nunca é usada nem alterada.
 */
export function SupportAccessDialog({ tenant, environment, onClose }: { tenant: SupportTarget | null; environment: string; onClose: () => void }) {
    const form = useForm({ reason: '', password: '' });

    useEffect(() => {
        form.reset();
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tenant?.id]);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (!tenant) return;
        form.post(route('admin.tenants.support.start', tenant.id), {
            preserveScroll: true,
            onFinish: () => form.reset('password'),
        });
    };

    const name = tenant ? tenant.company || tenant.name : '';

    return (
        <Dialog open={tenant !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Acessar painel da empresa</DialogTitle>
                        <DialogDescription>
                            Você entra com a sua identidade de RootAdmin. Operações financeiras, fiscais, de usuários, exclusões e pagamentos ficam
                            bloqueadas, e o acesso é registrado.
                        </DialogDescription>
                    </DialogHeader>

                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
                        <dt className="text-muted-foreground">Empresa</dt>
                        <dd className="font-medium break-words">{name}</dd>
                        {tenant?.cnpj && (
                            <>
                                <dt className="text-muted-foreground">CNPJ</dt>
                                <dd>{maskCnpj(tenant.cnpj)}</dd>
                            </>
                        )}
                        <dt className="text-muted-foreground">Assinatura</dt>
                        <dd>{tenant?.computed_subscription_label}</dd>
                        <dt className="text-muted-foreground">Ambiente</dt>
                        <dd>{environment}</dd>
                    </dl>

                    <div className="grid gap-1.5">
                        <Label htmlFor="support-reason">Motivo do acesso</Label>
                        <Textarea
                            id="support-reason"
                            maxLength={500}
                            placeholder="Ex.: cliente relatou erro ao salvar a OS 123"
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                            required
                        />
                        <InputError message={form.errors.reason} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="support-password">Sua senha (confirmação)</Label>
                        <Input
                            id="support-password"
                            type="password"
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                            required
                        />
                        <InputError message={form.errors.password} />
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing || form.data.reason.trim().length < 10 || form.data.password === ''}>
                            <LogIn className="size-4" />
                            Entrar no painel
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
