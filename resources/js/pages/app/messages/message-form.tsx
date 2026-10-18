import { toastSuccess } from '@/components/app-toast-messages';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import type { OptionType, PageProps, User } from '@/types';
import { Message } from '@/types';
import selectStyles from '@/Utils/selectStyles';
import { useForm, usePage } from '@inertiajs/react';
import { Save } from 'lucide-react';
import Select, { type SingleValue } from 'react-select';

interface MessageFormProps {
    initialData?: Message;
    users: User[];
}

export default function MessageForm({ users, initialData }: MessageFormProps) {
    const { auth } = usePage<PageProps>().props;

    const isEdit = !!initialData;
    const canEdit = !isEdit || initialData?.sender_id === auth.user.id;
    const optionsUser: OptionType[] = users.map((user) => ({
        value: user.id,
        label: user.name,
    }));

    const { data, setData, post, patch, processing, reset, errors } = useForm({
        sender_id: auth.user.id ?? '',
        recipient_id: initialData?.recipient_id ?? '',
        title: initialData?.title ?? '',
        message: initialData?.message ?? '',
        status: Boolean(initialData?.status),
    });

    const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (isEdit) {
            patch(route('app.messages.update', initialData!.id), {
                onSuccess: () => {
                    toastSuccess('Sucesso', 'Mensagem atualizada com sucesso');
                },
            });
        } else {
            post(route('app.messages.store'), {
                onSuccess: () => {
                    toastSuccess('Sucesso', 'Mensagem criado com sucesso');
                    reset();
                },
            });
        }
    };

    const changeMessageRecept = (selected: SingleValue<OptionType>) => {
        setData('recipient_id', typeof selected?.value === 'number' ? selected.value : '');
    };

    const defaultUser = optionsUser
        .filter((option) => option.value === initialData?.recipient_id)
        .map((option) => ({ value: option.value, label: option.label }));
    return (
        <form onSubmit={handleSubmit} autoComplete="off" className="space-y-8">
            <Card>
                <CardTitle className="border-b px-6 pb-4">Destinatário e título</CardTitle>
                <CardContent className="space-y-4 pt-6">
                    <div className="mt-4 grid gap-4 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="send_id">Remetente</Label>
                            <Input value={auth.user.name} readOnly type="text" id="sender" />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="recipient_id">Para</Label>
                            <Select
                                isDisabled={!canEdit}
                                menuPosition="fixed"
                                defaultValue={defaultUser}
                                options={optionsUser}
                                onChange={changeMessageRecept}
                                placeholder="Selecione o recebedor"
                                className="min-w-0"
                                styles={selectStyles}
                            />
                            <InputError className="mt-2" message={errors.recipient_id} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="service">Título</Label>
                        <Input readOnly={!canEdit} id="title" type="text" value={data.title} onChange={(e) => setData('title', e.target.value)} />
                        {errors.title && <div className="text-sm text-red-500">{errors.title}</div>}
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardTitle className="border-b px-6 pb-4">Mensagem</CardTitle>
                <CardContent className="space-y-4 pt-6">
                    <div className="grid gap-2">
                        <Label htmlFor="message">Mensagem</Label>
                        <Textarea readOnly={!canEdit} id="message" value={data.message} onChange={(e) => setData('message', e.target.value)} />
                        {errors.message && <div className="text-sm text-red-500">{errors.message}</div>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="status">Status da mensagem</Label>

                        <div className="flex items-center gap-3">
                            <Switch id="status" checked={data.status} onCheckedChange={(checked) => setData('status', checked)} />

                            <span className="text-muted-foreground text-sm">{data.status ? 'Lida' : 'Não lida'}</span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div className="flex justify-end">
                <Button type="submit" disabled={processing}>
                    <Save />
                    Salvar
                </Button>
            </div>
        </form>
    );
}
