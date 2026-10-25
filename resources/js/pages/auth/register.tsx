import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { maskCnpj, maskPhone, unMasCpfCnpj, unMask } from '@/Utils/mask';

type RegisterForm = {
    cnpj: string;
    company: string;
    name: string;
    email: string;
    phone: string;
    whatsapp: string;
    password: string;
    password_confirmation: string;
    accepted_terms: boolean;
};

export default function Register({ initialData = {} }: { initialData?: Partial<Pick<RegisterForm, 'name' | 'email' | 'whatsapp'>> }) {
    const [showPassword, setShowPassword] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm<Required<RegisterForm>>({
        cnpj: '',
        company: '',
        name: initialData.name ?? '',
        email: initialData.email ?? '',
        phone: '',
        whatsapp: initialData.whatsapp ?? '',
        password: '',
        password_confirmation: '',
        accepted_terms: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        setData((currentData) => ({
            ...currentData,
            cnpj: unMasCpfCnpj(currentData.cnpj),
            phone: unMask(currentData.phone) ?? '',
        }));

        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout width="w-full max-w-4xl" title="Criar uma conta" description="Digite seus dados abaixo para criar sua conta">
            <Head title="Criar uma conta" />
            <div className="min-w-0">
                <form className="flex flex-col gap-6" onSubmit={submit}>
                    <div className="grid gap-6 md:grid-cols-3">
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="company">Razão social</Label>
                            <Input
                                id="company"
                                type="text"
                                autoFocus
                                tabIndex={1}
                                autoComplete="company"
                                value={data.company}
                                onChange={(e) => setData('company', e.target.value)}
                                disabled={processing}
                                placeholder="Razão social"
                            />
                            <InputError message={errors.company} className="mt-2" />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="cnpj">CNPJ</Label>
                            <Input
                                id="cnpj"
                                type="text"
                                tabIndex={2}
                                autoComplete="cnpj"
                                value={maskCnpj(data.cnpj)}
                                onChange={(e) => setData('cnpj', e.target.value)}
                                disabled={processing}
                            />
                            <InputError message={errors.cnpj} className="mt-2" />
                        </div>
                    </div>
                    <div className="grid gap-6 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Nome completo</Label>
                            <Input
                                id="name"
                                type="text"
                                tabIndex={3}
                                autoComplete="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                disabled={processing}
                            />
                            <InputError message={errors.name} className="mt-2" />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">E-mail</Label>
                            <Input
                                id="email"
                                type="email"
                                tabIndex={4}
                                autoComplete="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                disabled={processing}
                            />
                            <InputError message={errors.email} />
                        </div>
                    </div>
                    <div className="grid gap-6 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="phone">Telefone</Label>
                            <Input
                                id="phone"
                                type="phone"
                                tabIndex={4}
                                autoComplete="phone"
                                value={maskPhone(data.phone)}
                                onChange={(e) => setData('phone', e.target.value)}
                                disabled={processing}
                                maxLength={15}
                            />
                            <InputError message={errors.phone} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="whatsapp">Whatsapp</Label>
                            <Input
                                id="whatsapp"
                                type="whatsapp"
                                tabIndex={4}
                                autoComplete="whatsapp"
                                value={maskPhone(data.whatsapp)}
                                onChange={(e) => setData('whatsapp', e.target.value)}
                                disabled={processing}
                                maxLength={15}
                            />
                            <InputError message={errors.whatsapp} />
                        </div>
                    </div>
                    <div className="grid gap-6">
                        <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-end">
                            <div className="flex min-w-0 flex-col gap-2">
                                <Label htmlFor="password">Senha</Label>
                                <Input
                                    id="password"
                                    type={showPassword ? 'text' : 'password'}
                                    tabIndex={5}
                                    autoComplete="new-password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    disabled={processing}
                                    placeholder="Senha"
                                />
                                <InputError message={errors.password} />
                            </div>
                            <Button type="button" variant="ghost" size="icon" onClick={() => setShowPassword(!showPassword)}>
                                {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                            </Button>
                            <div className="flex min-w-0 flex-col gap-2">
                                <Label htmlFor="password_confirmation">Confirmar senha</Label>
                                <Input
                                    id="password_confirmation"
                                    type={showPassword ? 'text' : 'password'}
                                    tabIndex={6}
                                    autoComplete="new-password"
                                    value={data.password_confirmation}
                                    onChange={(e) => setData('password_confirmation', e.target.value)}
                                    disabled={processing}
                                    placeholder="Confirmar senha"
                                />
                                <InputError message={errors.password_confirmation} />
                            </div>
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="accepted_terms"
                                checked={data.accepted_terms}
                                onCheckedChange={(checked) => setData('accepted_terms', checked === true)}
                                disabled={processing}
                                aria-invalid={Boolean(errors.accepted_terms)}
                            />
                            <Label htmlFor="accepted_terms" className="text-muted-foreground cursor-pointer leading-5 font-normal">
                                Li e aceito os{' '}
                                <a href="/termos" target="_blank" rel="noopener noreferrer" className="text-foreground underline underline-offset-4">
                                    Termos de Uso
                                </a>
                                .
                            </Label>
                        </div>
                        <InputError message={errors.accepted_terms} />
                    </div>
                    <Button type="submit" className="mt-2 w-full" tabIndex={7} disabled={processing}>
                        {processing ? (
                            <>
                                <svg className="mr-2 -ml-1 h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                    <path
                                        className="opacity-75"
                                        fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                    ></path>
                                </svg>
                                Cadastrando...
                            </>
                        ) : (
                            'Cadastrar'
                        )}
                    </Button>

                    <div className="text-muted-foreground text-center text-sm">
                        Já tem uma conta?{' '}
                        <TextLink href={route('login')} tabIndex={8}>
                            Entrar
                        </TextLink>
                    </div>
                </form>
            </div>
        </AuthLayout>
    );
}
