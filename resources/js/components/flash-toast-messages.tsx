import { toastError, toastSuccess, toastWarning } from '@/components/app-toast-messages';
import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';

type FlashMessages = {
    id?: string;
    success?: string;
    message?: string;
    error?: string;
    authorization_error?: string;
    import_success?: string;
    import_error?: string;
};

type InertiaResponse = {
    status?: number;
};

function friendlyErrorForStatus(status?: number) {
    if (!status) {
        return {
            title: 'Sem conexão com o servidor',
            description: 'Verifique sua internet e tente novamente.',
        };
    }

    if (status === 419) {
        return {
            title: 'Sessão expirada',
            description: 'Atualize a página e tente realizar a operação novamente.',
        };
    }

    if (status === 403) {
        return {
            title: 'Ação não autorizada',
            description: 'Esta ação não é autorizada.',
        };
    }

    if (status === 404) {
        return {
            title: 'Registro não encontrado',
            description: 'O item solicitado não está mais disponível ou foi removido.',
        };
    }

    if (status === 422) {
        return {
            title: 'Revise os dados informados',
            description: 'Alguns campos precisam ser corrigidos antes de continuar.',
        };
    }

    if (status === 429) {
        return {
            title: 'Muitas tentativas',
            description: 'Aguarde um instante e tente novamente.',
        };
    }

    return {
        title: 'Operação não concluída',
        description: 'Não foi possível concluir esta operação agora. Verifique sua conexão e tente novamente.',
    };
}

export function FlashToastMessages() {
    const { flash } = usePage<{ flash?: FlashMessages }>().props;

    useEffect(() => {
        const success = flash?.success ?? flash?.message ?? flash?.import_success;
        if (success) toastSuccess(success);
    }, [flash?.id, flash?.import_success, flash?.message, flash?.success]);

    useEffect(() => {
        if (flash?.authorization_error) {
            toastWarning('Ação não autorizada', flash.authorization_error);
            return;
        }

        const error = flash?.error ?? flash?.import_error;
        if (error) toastError(flash?.import_error ? 'Importação não concluída' : 'Operação não concluída', error);
    }, [flash?.authorization_error, flash?.error, flash?.id, flash?.import_error]);

    useEffect(() => {
        const handleInvalidResponse = (event: Event) => {
            const response = (event as CustomEvent<{ response?: InertiaResponse }>).detail?.response;

            if (response?.status && response.status >= 400) {
                const message = friendlyErrorForStatus(response.status);
                event.preventDefault();
                toastError(message.title, message.description);
            }
        };

        const handleException = (event: Event) => {
            const exception = (event as CustomEvent<{
                exception?: { response?: InertiaResponse; code?: string; message?: string };
            }>).detail?.exception;
            const isNetworkError = exception?.code === 'ERR_NETWORK' || exception?.message === 'Network Error';

            // Exceções de JavaScript também chegam aqui; não são falhas de conexão.
            if (!exception?.response && !isNetworkError) return;

            const message = friendlyErrorForStatus(exception?.response?.status);
            event.preventDefault();
            toastError(message.title, message.description);
        };

        document.addEventListener('inertia:invalid', handleInvalidResponse);
        document.addEventListener('inertia:exception', handleException);

        return () => {
            document.removeEventListener('inertia:invalid', handleInvalidResponse);
            document.removeEventListener('inertia:exception', handleException);
        };
    }, []);

    return null;
}
