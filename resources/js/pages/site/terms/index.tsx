import LegalLayout from '@/layouts/site/LegalLayout';
import { useActiveSection } from '@/Utils/use-active-section';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

const sections = [
    { id: 'descricao', title: '1. Descrição do serviço' },
    { id: 'cadastro', title: '2. Cadastro de conta' },
    { id: 'responsabilidade', title: '3. Responsabilidade do usuário' },
    { id: 'notas-fiscais', title: '4. Documentos fiscais' },
    { id: 'dados-integracoes', title: '5. Dados e integrações de terceiros' },
    { id: 'disponibilidade', title: '6. Disponibilidade do serviço' },
    { id: 'propriedade', title: '7. Propriedade intelectual' },
    { id: 'cancelamento', title: '8. Planos, cancelamento e suspensão' },
    { id: 'responsabilidade-limitada', title: '9. Limitação de responsabilidade' },
    { id: 'alteracoes', title: '10. Alterações nos termos' },
    { id: 'legislacao', title: '11. Legislação aplicável' },
    { id: 'contato', title: '12. Contato' },
];

export default function Terms() {
    const [open, setOpen] = useState(false);

    const activeSection = useActiveSection(sections.map((s) => s.id));

    return (
        <LegalLayout>
            <Head title="Termos de Uso" />
            <div className="grid grid-cols-1 gap-12 lg:grid-cols-[240px_1fr]">
                {/* SIDEBAR */}

                <aside className="hidden lg:block">
                    <nav className="sticky top-24 space-y-2 text-sm">
                        <p className="text-foreground mb-4 font-semibold">Índice</p>

                        {sections.map((section) => (
                            <a
                                key={section.id}
                                href={`#${section.id}`}
                                className={`block border-l py-1 pl-3 transition-colors ${
                                    activeSection === section.id
                                        ? 'border-primary text-foreground font-medium'
                                        : 'text-muted-foreground hover:text-foreground border-transparent'
                                } `}
                            >
                                {section.title}
                            </a>
                        ))}
                    </nav>
                </aside>

                {/* CONTEÚDO */}

                <article className="text-muted-foreground max-w-[72ch] text-[15px] leading-7 [&>p]:mb-6 [&>ul]:mb-8 [&>ul]:space-y-2">
                    <h1 className="text-foreground mb-6 text-3xl font-semibold tracking-tight">Termos de Uso – VetorOS</h1>

                    <p className="text-muted-foreground mb-10 text-sm">Última atualização: 14 de outubro de 2026</p>

                    <p>
                        Estes Termos de Uso regulam a utilização da plataforma VetorOS. Ao marcar a opção de aceite no cadastro, criar uma conta ou
                        utilizar o sistema, o usuário declara que leu, compreendeu e concorda com estes termos e com a{' '}
                        <a href="/privacidade" className="text-primary hover:underline">
                            Política de Privacidade
                        </a>
                        .
                    </p>

                    {/* DESCRIÇÃO */}

                    <h2 id="descricao" className="text-foreground border-border mt-16 mb-5 scroll-mt-28 border-b pb-2 text-xl font-semibold">
                        1. Descrição do serviço
                    </h2>

                    <p>
                        O VetorOS é uma plataforma online de gestão operacional destinada a empresas de assistência técnica, manutenção e serviços
                        recorrentes.
                    </p>

                    <p>A plataforma permite:</p>

                    <ul className="list-disc pl-6">
                        <li>Cadastro de clientes</li>
                        <li>Registro de equipamentos</li>
                        <li>Gerenciamento de ordens e atendimentos</li>
                        <li>Gestão independente de agendamentos e atividades de técnicos</li>
                        <li>Controle de peças e estoque</li>
                        <li>Armazenamento de informações de atendimento</li>
                        <li>Controle financeiro, caixa, despesas e vendas</li>
                        <li>Registro manual de documentos fiscais emitidos em sistemas externos</li>
                        <li>Envio de comunicações operacionais por e-mail e outros canais configurados pelo usuário</li>
                        <li>Acompanhamento público do cliente, acompanhamentos e indicadores operacionais</li>
                    </ul>

                    {/* CADASTRO */}

                    <h2 id="cadastro" className="text-foreground border-border mt-16 mb-5 scroll-mt-28 border-b pb-2 text-xl font-semibold">
                        2. Cadastro de conta
                    </h2>

                    <p>Para utilizar o sistema, o usuário deve fornecer informações verdadeiras, completas e atualizadas.</p>

                    <p>O usuário é responsável por:</p>

                    <ul className="list-disc pl-6">
                        <li>Manter a confidencialidade de suas credenciais</li>
                        <li>Todas as atividades realizadas em sua conta</li>
                        <li>Gerenciar os acessos e permissões concedidos aos seus colaboradores</li>
                        <li>Manter atualizados seus dados empresariais, fiscais e de contato</li>
                    </ul>

                    {/* RESPONSABILIDADE */}

                    <h2 id="responsabilidade" className="text-foreground border-border mt-16 mb-5 scroll-mt-28 border-b pb-2 text-xl font-semibold">
                        3. Responsabilidade do usuário
                    </h2>

                    <p>O usuário concorda em não utilizar o sistema para:</p>

                    <ul className="list-disc pl-6">
                        <li>Atividades ilegais</li>
                        <li>Envio de conteúdo malicioso</li>
                        <li>Tentativa de acesso não autorizado</li>
                        <li>Violação de direitos de terceiros</li>
                    </ul>

                    <p>
                        O usuário é responsável pela veracidade, legalidade e integridade dos dados inseridos na plataforma, incluindo informações de
                        clientes, equipamentos, ordens, vendas, despesas e registros operacionais.
                    </p>

                    <p>
                        O usuário também é responsável por possuir base legal para cadastrar e utilizar dados pessoais de clientes, colaboradores e
                        demais terceiros, bem como por atender solicitações dos titulares relacionadas aos dados sob sua responsabilidade.
                    </p>

                    {/* NOTAS FISCAIS */}

                    <h2 id="notas-fiscais" className="text-foreground border-border mt-16 mb-5 scroll-mt-28 border-b pb-2 text-xl font-semibold">
                        4. Documentos fiscais
                    </h2>

                    <p>
                        O VetorOS permite emitir NF-e, NFC-e e NFS-e por meio de integração com provedor fiscal parceiro, mediante liberação da
                        funcionalidade para a conta e envio do certificado digital A1 da empresa, e também registrar manualmente informações de
                        documentos emitidos em sistemas externos. O usuário é responsável pelos dados informados, pelas configurações tributárias,
                        pela guarda do certificado e de sua senha e pela conferência dos documentos autorizados ou rejeitados pela SEFAZ, prefeitura
                        ou ambiente nacional competente.
                    </p>

                    <p>
                        O VetorOS não presta consultoria contábil ou tributária. A definição dos tributos, enquadramentos, códigos e valores, assim
                        como o cumprimento de obrigações principais e acessórias, permanece sob responsabilidade exclusiva do usuário e de seus
                        assessores contábeis.
                    </p>

                    {/* DADOS E INTEGRAÇÕES */}

                    <h2 id="dados-integracoes" className="text-foreground border-border mt-16 mb-5 scroll-mt-28 border-b pb-2 text-xl font-semibold">
                        5. Dados e integrações de terceiros
                    </h2>

                    <p>
                        Para executar funcionalidades solicitadas pelo usuário, o VetorOS poderá compartilhar com fornecedores integrados apenas os
                        dados necessários à operação. Na integração fiscal, isso pode incluir dados da empresa emissora, clientes ou destinatários,
                        produtos, serviços, valores, tributos, endereços e demais informações constantes do documento fiscal.
                    </p>

                    <p>
                        O tratamento dessas informações também estará sujeito às políticas e aos termos do respectivo fornecedor. O usuário autoriza
                        esse compartilhamento ao habilitar e utilizar a integração e declara estar autorizado a transmitir os dados envolvidos.
                    </p>

                    {/* DISPONIBILIDADE */}

                    <h2 id="disponibilidade" className="text-foreground border-border mt-16 mb-5 scroll-mt-28 border-b pb-2 text-xl font-semibold">
                        6. Disponibilidade do serviço
                    </h2>

                    <p>Nos esforçamos para manter o sistema disponível continuamente.</p>

                    <p>Entretanto, o serviço poderá ficar temporariamente indisponível em situações como:</p>

                    <ul className="list-disc pl-6">
                        <li>Manutenção técnica programada</li>
                        <li>Falhas de infraestrutura</li>
                        <li>Eventos fora de nosso controle</li>
                        <li>Indisponibilidade de provedores de e-mail, hospedagem, pagamentos, SEFAZ, prefeituras ou outras integrações</li>
                    </ul>

                    {/* PROPRIEDADE */}

                    <h2 id="propriedade" className="text-foreground border-border mt-16 mb-5 scroll-mt-28 border-b pb-2 text-xl font-semibold">
                        7. Propriedade intelectual
                    </h2>

                    <p>
                        Todo o conteúdo da plataforma, incluindo software, design, logotipo e funcionalidades, é propriedade do VetorOS e protegido
                        por leis de propriedade intelectual.
                    </p>

                    {/* CANCELAMENTO */}

                    <h2 id="cancelamento" className="text-foreground border-border mt-16 mb-5 scroll-mt-28 border-b pb-2 text-xl font-semibold">
                        8. Planos, cancelamento e suspensão
                    </h2>

                    <p>
                        O acesso a determinadas funcionalidades pode depender do plano contratado, do pagamento da assinatura e da vigência do período
                        de teste ou contratação. O cancelamento não elimina valores já devidos nem obrigações legais de guarda de documentos e
                        informações.
                    </p>

                    <p>Reservamo-nos o direito de suspender ou cancelar contas que:</p>

                    <ul className="list-disc pl-6">
                        <li>Violem estes termos</li>
                        <li>Utilizem o sistema de forma abusiva</li>
                        <li>Comprometam a segurança da plataforma</li>
                    </ul>

                    {/* RESPONSABILIDADE LIMITADA */}

                    <h2
                        id="responsabilidade-limitada"
                        className="text-foreground border-border mt-16 mb-5 scroll-mt-28 border-b pb-2 text-xl font-semibold"
                    >
                        9. Limitação de responsabilidade
                    </h2>

                    <ul className="list-disc pl-6">
                        <li>Perda de dados causada por uso indevido da plataforma</li>
                        <li>Danos indiretos ou lucros cessantes decorrentes do uso do sistema</li>
                        <li>Falhas em serviços ou integrações de terceiros</li>
                        <li>
                            Rejeição, atraso, duplicidade ou indisponibilidade de documentos fiscais decorrente de dados incorretos ou de sistemas
                            externos
                        </li>
                        <li>
                            Multas, autuações ou prejuízos decorrentes de classificação fiscal, tributação ou cumprimento de obrigações do usuário
                        </li>
                    </ul>

                    {/* ALTERAÇÕES */}

                    <h2 id="alteracoes" className="text-foreground border-border mt-16 mb-5 scroll-mt-28 border-b pb-2 text-xl font-semibold">
                        10. Alterações nos termos
                    </h2>

                    <p>
                        Estes Termos de Uso podem ser modificados a qualquer momento. O uso contínuo da plataforma após alterações implica
                        concordância com os novos termos.
                    </p>

                    {/* LEGISLAÇÃO */}

                    <h2 id="legislacao" className="text-foreground border-border mt-16 mb-5 scroll-mt-28 border-b pb-2 text-xl font-semibold">
                        11. Legislação aplicável
                    </h2>

                    <p>Estes termos são regidos pelas leis da República Federativa do Brasil.</p>

                    {/* CONTATO */}

                    <h2 id="contato" className="text-foreground border-border mt-16 mb-5 scroll-mt-28 border-b pb-2 text-xl font-semibold">
                        12. Contato
                    </h2>

                    <p>
                        Email:{' '}
                        <a href="mailto:contato@vetoros.com.br" className="text-primary hover:underline">
                            contato@vetoros.com.br
                        </a>
                    </p>
                </article>
            </div>
        </LegalLayout>
    );
}
