<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.landing')] class extends Component {
    //
}; ?>

<div class="landing-page">
    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="hero-content">
                <div class="hero-text">
                    <h1 class="hero-title">
                        <span class="gradient-text">Toolzapp</span>
                        <br>Revolucione seu Atendimento com agentes de IA
                    </h1>
                    <p class="hero-description">
                        Plataforma inteligente de Agentes de IA para WhatsApp com memória de longo prazo.
                        Atendimento automatizado que realmente aprende e nunca perde o contexto.
                    </p>
                    <div class="hero-buttons">
                        <a href="{{ route('register') }}" class="btn btn-primary-hero">
                            Começar Agora
                            <i class="ri-arrow-right-line"></i>
                        </a>
                        <a href="#planos" class="btn btn-secondary-hero">
                            Ver Planos
                        </a>
                    </div>
                </div>
                <div class="hero-visual">
                    <div class="floating-card card-1">
                        <i class="ri-whatsapp-fill"></i>
                        <span>WhatsApp</span>
                    </div>
                    <div class="floating-card card-2">
                        <i class="ri-brain-line"></i>
                        <span>IA Inteligente</span>
                    </div>
                    <div class="floating-card card-3">
                        <i class="ri-database-2-line"></i>
                        <span>Memória Persistente</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="recursos" class="features-section">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Recursos Revolucionários</h2>
                <p class="section-subtitle">
                    Tecnologia de ponta que transforma a forma como você atende seus clientes
                </p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="ri-brain-line"></i>
                    </div>
                    <h3 class="feature-title">IA com Memória Persistente</h3>
                    <p class="feature-description">
                        Agentes que realmente aprendem e mantêm memórias de curto e longo prazo.
                        Nunca perca o contexto, mesmo após milhares de interações.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="ri-whatsapp-line"></i>
                    </div>
                    <h3 class="feature-title">Atendimento via WhatsApp</h3>
                    <p class="feature-description">
                        Integração completa com WhatsApp para atendimento automatizado 24/7.
                        Seus clientes recebem respostas instantâneas e personalizadas.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="ri-team-line"></i>
                    </div>
                    <h3 class="feature-title">Diálogos Internos</h3>
                    <p class="feature-description">
                        Gestão e diálogos internos na plataforma. Colabore com sua equipe
                        e gerencie conversas de forma centralizada e eficiente.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="ri-stack-line"></i>
                    </div>
                    <h3 class="feature-title">Sem Limites de Contexto</h3>
                    <p class="feature-description">
                        Nossa tecnologia avançada permite que os agentes processem informações
                        ilimitadas sem necessidade de reiniciar ou perder conhecimento.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="ri-robot-line"></i>
                    </div>
                    <h3 class="feature-title">Agentes Personalizáveis</h3>
                    <p class="feature-description">
                        Crie e configure agentes de IA únicos para cada necessidade.
                        Personalize comportamento, tom e conhecimento específico do seu negócio.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="ri-bar-chart-line"></i>
                    </div>
                    <h3 class="feature-title">Analytics Avançado</h3>
                    <p class="feature-description">
                        Acompanhe métricas detalhadas de desempenho, satisfação do cliente
                        e eficiência dos seus agentes em tempo real.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section id="como-funciona" class="how-it-works-section">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Como Funciona</h2>
                <p class="section-subtitle">
                    Simples, rápido e poderoso. Veja como transformar seu atendimento em minutos
                </p>
            </div>
            <div class="steps-container">
                <div class="step-item">
                    <div class="step-number">01</div>
                    <div class="step-content">
                        <h3>Crie seu Agente</h3>
                        <p>Configure seu agente de IA personalizado com as informações e comportamento desejados</p>
                    </div>
                </div>
                <div class="step-connector"></div>
                <div class="step-item">
                    <div class="step-number">02</div>
                    <div class="step-content">
                        <h3>Conecte ao WhatsApp</h3>
                        <p>Integre facilmente com WhatsApp e comece a receber mensagens dos seus clientes</p>
                    </div>
                </div>
                <div class="step-connector"></div>
                <div class="step-item">
                    <div class="step-number">03</div>
                    <div class="step-content">
                        <h3>Aprenda e Evolua</h3>
                        <p>Seu agente aprende com cada interação, melhorando continuamente o atendimento</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="planos" class="pricing-section features-section">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Planos e Preços</h2>
                <p class="section-subtitle">
                    Escolha o plano perfeito para impulsionar seu negócio com inteligência artificial
                </p>
            </div>

            <div class="pricing-grid">
                <!-- Plano Silver -->
                <div class="pricing-card">
                    <div class="pricing-header">
                        <div class="pricing-icon">
                            <i class="ri-star-line"></i>
                        </div>
                        <h3 class="pricing-title">Plano Silver</h3>
                        <p class="pricing-subtitle">Ideal para pequenos negócios</p>
                    </div>

                    <div class="pricing-price">
                        <span class="price-tag">R$ 99,90<span class="period">/mês</span></span>
                    </div>

                    <div class="pricing-content">
                        <div class="pricing-features">
                            <ul class="features-list">
                                <li><i class="ri-check-line"></i> <strong> 2 Agentes de IA especializados</strong> -
                                    Assistentes virtuais personalizados para diferentes tipos de atendimento</li>
                                <li><i class="ri-check-line"></i> <strong> 500 Créditos mensais</strong> - Conversas
                                    ilimitadas com tokens suficientes para interações completas</li>
                                <li><i class="ri-check-line"></i> <strong> 1 Instância WhatsApp certificada</strong> -
                                    Conexão direta e oficial com a plataforma WhatsApp</li>
                                <li><i class="ri-check-line"></i> <strong> 1 Membro da equipe</strong> - Acesso
                                    administrativo para configuração e monitoramento</li>
                                <li><i class="ri-check-line"></i> <strong> Memória persistente avançada</strong> -
                                    Contexto mantido indefinidamente entre conversas</li>
                                <li><i class="ri-check-line"></i> <strong> Analytics básicos</strong> - Relatórios
                                    essenciais de desempenho e engajamento</li>
                                <li><i class="ri-check-line"></i> <strong> Suporte via chat comercial</strong> -
                                    Assistência técnica durante horário comercial brasileiro</li>
                            </ul>
                        </div>

                        <div class="pricing-description">
                            <p>O Plano Silver representa o ponto de entrada perfeito para empreendedores visionários e
                                pequenos negócios que buscam revolucionar seu atendimento ao cliente. Com 2 agentes de
                                IA especializados, você terá assistentes virtuais capazes de manter conversas naturais e
                                contextuais.</p>
                        </div>
                    </div>
                </div>

                <!-- Plano Gold -->
                <div class="pricing-card pricing-popular">
                    <div class="pricing-header">
                        <div class="pricing-icon">
                            <i class="ri-vip-crown-line"></i>
                        </div>
                        <h3 class="pricing-title">Plano Gold</h3>
                        <p class="pricing-subtitle">Para empresas em crescimento</p>
                    </div>

                    <div class="pricing-price">
                        <span class="price-tag">R$ 129,90<span class="period">/mês</span></span>
                    </div>

                    <div class="pricing-content">
                        <div class="pricing-features">
                            <ul class="features-list">
                                <li><i class="ri-check-line"></i> <strong> 5 Agentes de IA multitarefas</strong> -
                                    Equipe
                                    completa de assistentes especializados por setor</li>
                                <li><i class="ri-check-line"></i> <strong> 800 Créditos mensais ampliados</strong> -
                                    Capacidade para alto volume de interações diárias</li>
                                <li><i class="ri-check-line"></i> <strong> 2 Instâncias WhatsApp simultâneas</strong> -
                                    Segmentação por departamento ou público-alvo</li>
                                <li><i class="ri-check-line"></i> <strong> 3 Membros da equipe colaborativa</strong> -
                                    Gestão compartilhada e trabalho em equipe</li>
                                <li><i class="ri-check-line"></i> <strong> Analytics avançados com BI</strong> -
                                    Dashboards detalhados com métricas de performance</li>
                                <li><i class="ri-check-line"></i> <strong> Diálogos internos entre agentes</strong> -
                                    Coordenação inteligente entre assistentes virtuais</li>
                                <li><i class="ri-check-line"></i> <strong> Suporte prioritário garantido</strong> -
                                    Resposta em até 4 horas em qualquer dia da semana</li>
                                <li><i class="ri-check-line"></i> <strong> Programa de indicações 10%</strong> - Ganhe
                                    comissão sobre novos clientes indicados</li>
                            </ul>
                        </div>

                        <div class="pricing-description">
                            <p>O Plano Gold é a escolha estratégica para empresas em crescimento que demandam uma
                                solução robusta e escalável. Com 5 agentes de IA multitarefas para diferentes setores do
                                negócio.</p>
                        </div>
                    </div>
                </div>

                <!-- Plano Black -->
                <div class="pricing-card">
                    <div class="pricing-header">
                        <div class="pricing-icon">
                            <i class="ri-shield-fill"></i>
                        </div>
                        <h3 class="pricing-title">Plano Black</h3>
                        <p class="pricing-subtitle">Solução completa para grandes empresas</p>
                    </div>

                    <div class="pricing-price">
                        <span class="price-tag">R$ 149,90<span class="period">/mês</span></span>
                    </div>

                    <div class="pricing-content">
                        <div class="pricing-features">
                            <ul class="features-list">
                                <li><i class="ri-check-line"></i> <strong> 8 Agentes de IA empresariais</strong> -
                                    Equipe
                                    virtual completa para operações complexas e multi-setoriais</li>
                                <li><i class="ri-check-line"></i> <strong> 1000 Créditos mensais premium</strong> -
                                    Capacidade ilimitada para empresas de alto tráfego</li>
                                <li><i class="ri-check-line"></i> <strong> 4 Instâncias WhatsApp empresariais</strong>
                                    -
                                    Suporte a múltiplas unidades, regiões ou segmentos de negócio</li>
                                <li><i class="ri-check-line"></i> <strong> 8 Membros da equipe empresarial</strong> -
                                    Gestão corporativa com controle de acessos granular</li>
                                <li><i class="ri-check-line"></i> <strong> Business Intelligence avançado</strong> -
                                    Dashboards executivos com análise preditiva e KPIs estratégicos</li>
                                <li><i class="ri-check-line"></i> <strong> Integrações corporativas completas</strong>
                                    -
                                    Conexão nativa com CRMs, ERPs e sistemas legados</li>
                                <li><i class="ri-check-line"></i> <strong> API dedicada de alto desempenho</strong> -
                                    Desenvolvimento personalizado e integrações customizadas</li>
                                <li><i class="ri-check-line"></i> <strong> Suporte 24/7 com gerente dedicado</strong> -
                                    Assistência especializada com profissional exclusivo</li>
                                <li><i class="ri-check-line"></i> <strong> Programa de parcerias 20%</strong> -
                                    Comissão
                                    elevada e benefícios exclusivos para parceiros</li>
                            </ul>
                        </div>

                        <div class="pricing-description">
                            <p>O Plano Black representa o ápice da automação inteligente para grandes corporações e
                                empresas que demandam excelência operacional em escala empresarial.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-10 pricing-footer">
                <div class="pricing-guarantee">
                    <div>
                        <h4> <i class="text-white ri-shield-check-line"></i> <span class="gradient-text"> Garantia de
                                7 dias</span></h4>
                        <p>Teste gratuito sem compromisso. Cancele a qualquer momento.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Benefits Section -->
    <section class="benefits-section">
        <div class="container">
            <div class="benefits-content">
                <div class="benefits-text">
                    <h2 class="section-title">Por Que Escolher Toolzapp?</h2>
                    <div class="benefit-list">
                        <div class="benefit-item">
                            <i class="ri-checkbox-circle-fill"></i>
                            <div>
                                <h4>Memória de Longo Prazo</h4>
                                <p>Única plataforma que mantém contexto completo sem limites</p>
                            </div>
                        </div>
                        <div class="benefit-item">
                            <i class="ri-checkbox-circle-fill"></i>
                            <div>
                                <h4>Atendimento 24/7</h4>
                                <p>Nunca perca uma oportunidade, mesmo fora do horário comercial</p>
                            </div>
                        </div>
                        <div class="benefit-item">
                            <i class="ri-checkbox-circle-fill"></i>
                            <div>
                                <h4>Escalabilidade Ilimitada</h4>
                                <p>Atenda milhares de clientes simultaneamente sem aumentar custos</p>
                            </div>
                        </div>
                        <div class="benefit-item">
                            <i class="ri-checkbox-circle-fill"></i>
                            <div>
                                <h4>Fácil Integração</h4>
                                <p>Configure e comece a usar em minutos, sem necessidade de conhecimento técnico</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="benefits-visual">
                    <div class="stats-card">
                        <div class="stat-item">
                            <div class="stat-number">99.9%</div>
                            <div class="stat-label">Uptime</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">24/7</div>
                            <div class="stat-label">Disponibilidade</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">∞</div>
                            <div class="stat-label">Memória</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="container">
            <div class="cta-content">
                <h2 class="cta-title">Pronto para Revolucionar seu Atendimento?</h2>
                <p class="cta-description">
                    Junte-se a empresas que já estão transformando a experiência dos clientes com IA
                </p>
                <div class="cta-buttons">
                    <a href="{{ route('register') }}" class="btn btn-cta-primary">
                        Criar Conta Gratuita
                        <i class="ri-arrow-right-line"></i>
                    </a>
                    <a href="{{ route('login') }}" class="btn btn-cta-secondary">
                        Fazer Login
                    </a>
                </div>
            </div>
        </div>
    </section>
</div>
