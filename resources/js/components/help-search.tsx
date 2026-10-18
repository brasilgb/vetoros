import { CommandDialog, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList, CommandShortcut } from '@/components/ui/command';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import axios from 'axios';
import { BookOpenText, Loader2Icon, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from './ui/button';

type HelpTopicSummary = {
    id: number;
    slug: string;
    title: string;
    category: string | null;
};

type HelpTopicContent = {
    title: string;
    content: string;
};

export default function HelpSearch() {
    const [open, setOpen] = useState(false);
    const [topics, setTopics] = useState<HelpTopicSummary[]>([]);
    const [selected, setSelected] = useState<HelpTopicContent | null>(null);
    const [sheetOpen, setSheetOpen] = useState(false);
    const [loadingTopic, setLoadingTopic] = useState(false);

    useEffect(() => {
        axios
            .get<HelpTopicSummary[]>(route('app.help-topics.index'))
            .then((response) => setTopics(response.data))
            .catch(() => {
                // Busca de ajuda é auxiliar; falha silenciosa não deve atrapalhar o uso do sistema.
            });
    }, []);

    useEffect(() => {
        const handleKeyDown = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setOpen((current) => !current);
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, []);

    const groups = useMemo(() => {
        const map = new Map<string, HelpTopicSummary[]>();
        for (const topic of topics) {
            const key = topic.category ?? 'Geral';
            if (!map.has(key)) {
                map.set(key, []);
            }
            map.get(key)!.push(topic);
        }
        return Array.from(map.entries());
    }, [topics]);

    const selectTopic = async (slug: string) => {
        setOpen(false);
        setLoadingTopic(true);
        setSheetOpen(true);

        try {
            const response = await axios.get<HelpTopicContent>(route('app.help-topics.show', slug));
            setSelected(response.data);
        } catch {
            setSheetOpen(false);
            toast.error('Não foi possível carregar esse tópico de ajuda agora.');
        } finally {
            setLoadingTopic(false);
        }
    };

    return (
        <>
            <Button variant="outline" size="sm" className="text-muted-foreground gap-2" onClick={() => setOpen(true)}>
                <Search className="h-4 w-4" />
                <span className="hidden lg:inline">Buscar ajuda...</span>
                <CommandShortcut className="hidden lg:inline">Ctrl K</CommandShortcut>
            </Button>

            <CommandDialog open={open} onOpenChange={setOpen} title="Buscar ajuda" description="Pesquise tópicos do manual do VetorOS">
                <CommandInput placeholder="Digite para buscar um tópico do manual..." />
                <CommandList>
                    <CommandEmpty>Nenhum tópico encontrado.</CommandEmpty>
                    {groups.map(([category, items]) => (
                        <CommandGroup key={category} heading={category}>
                            {items.map((topic) => (
                                <CommandItem key={topic.id} value={topic.title} onSelect={() => selectTopic(topic.slug)}>
                                    <BookOpenText />
                                    {topic.title}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    ))}
                </CommandList>
            </CommandDialog>

            <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                <SheetContent side="right" className="flex w-full flex-col gap-0 sm:max-w-lg">
                    <SheetHeader>
                        <SheetTitle>{selected?.title ?? 'Carregando...'}</SheetTitle>
                    </SheetHeader>
                    <div className="min-h-0 flex-1 overflow-y-auto px-4 pb-4">
                        {loadingTopic ? (
                            <div className="text-muted-foreground flex items-center gap-2 text-sm">
                                <Loader2Icon className="h-4 w-4 animate-spin" />
                                Carregando tópico...
                            </div>
                        ) : (
                            selected && (
                                <div
                                    className="[&_.callout]:bg-muted [&_a]:text-primary max-w-none text-sm leading-relaxed [&_.callout]:my-3 [&_.callout]:rounded-lg [&_.callout]:border [&_.callout]:p-3 [&_.callout]:text-sm [&_a]:underline [&_h1]:mt-4 [&_h1]:mb-2 [&_h1]:text-lg [&_h1]:font-semibold [&_h2]:mt-4 [&_h2]:mb-2 [&_h2]:text-base [&_h2]:font-semibold [&_h3]:mt-3 [&_h3]:mb-1 [&_h3]:font-medium [&_li]:mb-1 [&_ol]:mb-3 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-3 [&_ul]:mb-3 [&_ul]:list-disc [&_ul]:pl-5"
                                    // Conteúdo confiável: importado apenas por usuários root (tenant_id nulo) via /admin.
                                    dangerouslySetInnerHTML={{ __html: selected.content }}
                                />
                            )
                        )}
                    </div>
                </SheetContent>
            </Sheet>
        </>
    );
}
