export const APP_STATUS_CONFIGS = {
    ordem: {
        1: { label: 'Ordem Aberta', color: 'bg-blue-100 text-blue-700 border-blue-200' },
        2: { label: 'Ordem Cancelada', color: 'bg-red-100 text-red-800 border-red-200' },
        3: { label: 'Orçamento Gerado', color: 'bg-amber-100 text-amber-700 border-amber-200' },
        4: { label: 'Orçamento Aprovado', color: 'bg-indigo-100 text-indigo-700 border-indigo-200' },
        5: { label: 'Orçamento reprovado', color: 'bg-red-100 text-red-700 border-red-200' },
        6: { label: 'Reparo em andamento', color: 'bg-purple-100 text-purple-700 border-purple-200' },
        7: { label: 'Serviço concluído', color: 'bg-emerald-100 text-emerald-700 border-emerald-200' },
        8: { label: 'Serviço não executado', color: 'bg-red-600 text-white border-red-400' },
        9: { label: 'Cliente avisado / aguardando retirada', color: 'bg-teal-100 text-teal-700 border-teal-200' },
        10: { label: 'Entregue ao cliente', color: 'bg-green-600 text-white border-green-700' },
        13: { label: 'Em diagnóstico', color: 'bg-sky-100 text-sky-700 border-sky-200' },
        14: { label: 'Aguardando peça', color: 'bg-orange-100 text-orange-700 border-orange-200' },
        15: { label: 'Aguardando cliente', color: 'bg-yellow-100 text-yellow-800 border-yellow-200' },
    },
    userStatus: {
        0: { label: 'Inativo', color: 'bg-red-100 text-red-700 border-red-200' },
        1: { label: 'Ativo', color: 'bg-green-100 text-green-700 border-green-200' },
    },
    role: {
        99: { label: 'RootSystem', color: 'bg-green-100 text-green-700 border-green-200' },
        9: { label: 'RootApp', color: 'bg-rose-600 text-white border-rose-700' },
        1: { label: 'Administrador', color: 'bg-violet-100 text-violet-700 border-violet-200' },
        2: { label: 'Operador/Atendente', color: 'bg-blue-100 text-blue-700 border-blue-200' },
        3: { label: 'Técnico', color: 'bg-cyan-100 text-cyan-700 border-cyan-200' },
    },
    agenda: {
        1: { label: 'Aberta', color: 'bg-blue-100 text-blue-700 border-blue-200' },
        2: { label: 'Em atendimento', color: 'bg-amber-100 text-amber-700 border-amber-200' },
        3: { label: 'Fechada', color: 'bg-green-100 text-green-700 border-green-200' },
    },
    message: {
        0: { label: 'Não lida', color: 'bg-blue-600 text-white font-bold' },
        1: { label: 'Lida', color: 'bg-gray-100 text-gray-500 border-gray-200' },
    },
    saas: {
        1: { label: 'Ativo', color: 'bg-green-100 text-green-700 border-green-200' },
        2: { label: 'Inativo', color: 'bg-red-100 text-red-700 border-red-200' },
        3: { label: 'Trial', color: 'bg-indigo-100 text-indigo-700 border-indigo-200' },
        4: { label: 'Pausado', color: 'bg-orange-100 text-orange-700 border-orange-200' },
        5: { label: 'Vence em 5D', color: 'bg-red-600 text-white animate-pulse' }, // Destaque para urgência
    },
} as const;
