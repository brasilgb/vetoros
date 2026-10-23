import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { connectBackend } from '@/Utils/connectApi';
import { useForm } from '@inertiajs/react';
import { Loader2, Smartphone } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type ConnectionStatus = 'disconnected' | 'starting' | 'qr_required' | 'connected' | 'failed';

type Connection = {
    status: ConnectionStatus;
    phone_number?: string | null;
};

const STATUS_LABEL: Record<ConnectionStatus, string> = {
    disconnected: 'Não conectado',
    starting: 'Iniciando sessão...',
    qr_required: 'Aguardando leitura do QR Code',
    connected: 'Conectado',
    failed: 'Falha na conexão',
};

const STATUS_BADGE_CLASS: Record<ConnectionStatus, string> = {
    disconnected: 'bg-muted text-muted-foreground',
    starting: 'bg-amber-100 text-amber-800',
    qr_required: 'bg-amber-100 text-amber-800',
    connected: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-red-100 text-red-800',
};

const POLLING_STATUSES: ConnectionStatus[] = ['starting', 'qr_required'];

export default function WhatsappConnectionPanel({ connection: initialConnection }: { connection: Connection }) {
    const [connection, setConnection] = useState<Connection>(initialConnection);
    const [qrVersion, setQrVersion] = useState(0);
    const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const { post, processing } = useForm({});

    const stopPolling = () => {
        if (pollingRef.current) {
            clearInterval(pollingRef.current);
            pollingRef.current = null;
        }
    };

    const fetchStatus = async () => {
        try {
            const response = await connectBackend.get<Connection>('whatsapp-connection/status');
            setConnection(response.data);

            if (POLLING_STATUSES.includes(response.data.status)) {
                setQrVersion((v) => v + 1);
            }
        } catch {
            // Silencioso: a próxima tentativa de polling tenta de novo.
        }
    };

    useEffect(() => {
        if (POLLING_STATUSES.includes(connection.status) && !pollingRef.current) {
            pollingRef.current = setInterval(fetchStatus, 3000);
        }

        if (!POLLING_STATUSES.includes(connection.status)) {
            stopPolling();
        }

        return stopPolling;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [connection.status]);

    useEffect(() => stopPolling, []);

    const handleConnect = () => {
        post(route('app.whatsapp-connection.connect'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => fetchStatus(),
        });
    };

    const handleDisconnect = () => {
        post(route('app.whatsapp-connection.disconnect'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setConnection({ status: 'disconnected', phone_number: null }),
        });
    };

    const showQrCode = POLLING_STATUSES.includes(connection.status);

    return (
        <div className="space-y-4 rounded-lg border p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <Smartphone className="h-5 w-5" />
                    <div>
                        <p className="font-medium">WhatsApp</p>
                        <div className="mt-1 flex items-center gap-2">
                            <Badge className={STATUS_BADGE_CLASS[connection.status]}>{STATUS_LABEL[connection.status]}</Badge>
                            {connection.status === 'connected' && connection.phone_number && (
                                <span className="text-muted-foreground text-sm">{connection.phone_number}</span>
                            )}
                        </div>
                    </div>
                </div>

                {connection.status === 'connected' ? (
                    <Button type="button" variant="outline" onClick={handleDisconnect} disabled={processing}>
                        Desconectar
                    </Button>
                ) : (
                    <Button type="button" onClick={handleConnect} disabled={processing || showQrCode}>
                        {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                        Conectar WhatsApp
                    </Button>
                )}
            </div>

            {showQrCode && (
                <div className="flex flex-col items-center gap-2 rounded-md border border-dashed p-4">
                    <img
                        key={qrVersion}
                        src={`${route('app.whatsapp-connection.qr')}?t=${qrVersion}`}
                        alt="QR Code do WhatsApp"
                        className="h-56 w-56 rounded-md border bg-white p-2"
                    />
                    <p className="text-muted-foreground max-w-sm text-center text-sm">
                        Abra o WhatsApp no celular da empresa em <strong>Aparelhos conectados → Conectar um aparelho</strong> e escaneie o código
                        acima.
                    </p>
                </div>
            )}

            {connection.status === 'failed' && (
                <p className="text-sm text-red-600">
                    A sessão foi encerrada, geralmente porque o QR Code não foi lido a tempo. Clique em Conectar WhatsApp para gerar um novo.
                </p>
            )}
        </div>
    );
}
