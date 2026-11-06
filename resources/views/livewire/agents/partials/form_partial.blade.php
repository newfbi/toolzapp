<div class="col-sm-12">
    <div class="card">
        <div class="card-body" style="position: relative;">
            <!-- Loading Overlay -->
            @if ($isProcessing)
                <div class="top-0 position-absolute d-flex align-items-center justify-content-center w-100 h-100 start-0"
                    style="background: rgba(255, 255, 255, 0.95); z-index: 1050; border-radius: 0.5rem; min-height: 400px;">
                    <div class="px-4 text-center">
                        <div class="mb-3 spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                            <span class="visually-hidden">Processando...</span>
                        </div>
                        <h5 class="mb-2 text-primary">Processando...</h5>
                        <p class="mb-2 text-muted">
                            @if (!empty($generalTraining))
                                Gerando treinamento do agente com IA. Isso pode levar alguns segundos.
                            @else
                                Criando agente...
                            @endif
                        </p>
                        <small class="d-block text-muted">Por favor, aguarde e não feche esta página.</small>
                    </div>
                </div>
            @endif

            <div class="horizontal-wizard-wrapper">
                <form class="g-3 needs-validation" wire:submit.prevent="createAgent">
                    <div class="row g-3">
                        <div class="col-12 main-horizontal-header">
                            <div class="nav nav-pills horizontal-options" id="horizontal-wizard-tab" role="tablist"
                                aria-orientation="vertical">
                                <a class="nav-link {{ $activeTab == 'wizard-info' ? 'active' : '' }} @if ($isProcessing) disabled @endif"
                                    id="wizard-info-tab" data-bs-toggle="pill" href="#wizard-info" role="tab"
                                    aria-controls="wizard-info" aria-selected="true"
                                    wire:click="setActiveTab('wizard-info')"
                                    @if ($isProcessing) style="pointer-events: none; opacity: 0.6;" @endif>
                                    <div class="horizontal-wizard">
                                        <div class="stroke-icon-wizard"><i class="ri-user-line"></i></div>
                                        <div class="horizontal-wizard-content">
                                            <h6>Informações Básicas</h6>
                                        </div>
                                    </div>
                                </a>
                                <a class="nav-link {{ $activeTab == 'training-wizard' ? 'active' : '' }} @if ($isProcessing) disabled @endif"
                                    id="training-wizard-tab" data-bs-toggle="pill" href="#training-wizard"
                                    role="tab" aria-controls="training-wizard" aria-selected="false"
                                    wire:click="setActiveTab('training-wizard')"
                                    @if ($isProcessing) style="pointer-events: none; opacity: 0.6;" @endif>
                                    <div class="horizontal-wizard">
                                        <div class="stroke-icon-wizard"><i class="ri-graduation-cap-line"></i></div>
                                        <div class="horizontal-wizard-content">
                                            <h6>Treinamento e Comportamento</h6>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="tab-content dark-field" id="horizontal-wizard-tabContent">
                                {{-- ABA 1: INFORMAÇÕES BÁSICAS --}}
                                <div class="tab-pane fade {{ $activeTab == 'wizard-info' ? 'show active' : '' }}"
                                    id="wizard-info" role="tabpanel" aria-labelledby="wizard-info-tab">
                                    <div class="row">
                                        <div class="mb-2 col-md-6">
                                            <div class="card social-profile">
                                                <div class="card-body">
                                                    <div class="d-flex flex-column align-items-center text-center">
                                                        <div class="mb-3">
                                                            @if (is_object($avatar) && method_exists($avatar, 'temporaryUrl'))
                                                                <img width="120px" height="120px"
                                                                    class="rounded-circle img-fluid"
                                                                    src="{{ $avatar->temporaryUrl() }}"
                                                                    style="object-fit: cover;" />
                                                            @else
                                                                <img width="120px" height="120px"
                                                                    class="rounded-circle img-fluid"
                                                                    src="{{ isset($avatar) ? '/assets/img/' . $avatar : '/assets/images/avatar/default.png' }}"
                                                                    style="object-fit: cover;" />
                                                            @endif
                                                        </div>
                                                        <h5 class="mb-3">Avatar do Agente</h5>
                                                        <label for="avatar" class="mb-2 btn btn-primary">
                                                            <i class="me-2 ri-upload-cloud-line"></i>Enviar Imagem
                                                        </label>
                                                        <input type="file" class="d-none" id="avatar"
                                                            wire:model="avatar" accept="image/*" required>
                                                        <small class="text-muted">Formatos aceitos: JPG, PNG,
                                                            GIF</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mb-2 col-md-6">
                                            <div class="border rounded-3 card-wrapper rtl-input checkbox-checked"
                                                style="padding: 15px;">
                                                <h6 class="sub-title">Configurações de Mídia</h6>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch"
                                                        wire:model="isPersonal">
                                                    <label class="form-check-label">
                                                        Agente Pessoal
                                                        <small class="d-block text-muted">Ativar este campo o agente irá
                                                            responder apenas ao seu contato.</small>
                                                    </label>
                                                </div>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch"
                                                        wire:model="audio">
                                                    <label class="form-check-label">Aceitar Áudio</label>
                                                </div>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch"
                                                        wire:model="midia">
                                                    <label class="form-check-label">Aceitar Imagem</label>
                                                </div>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch"
                                                        wire:model="saudacao">
                                                    <label class="form-check-label">Usar Saudação Personalizada</label>
                                                </div>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch"
                                                        wire:model="nameUser">
                                                    <label class="form-check-label">Solicitar Nome do Usuário</label>
                                                </div>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch"
                                                        wire:model="autoFollowup">
                                                    <label class="form-check-label">
                                                        Ativar Follow-up Automático
                                                        <small class="d-block text-muted">O agente enviará mensagens de
                                                            follow-up automaticamente.</small>
                                                    </label>
                                                </div>
                                                <div class="d-flex align-items-center gap-2 mt-3">
                                                    <input wire:model="leadInterval" type="number"
                                                        id="lead_interval"
                                                        class="text-center form-control form-control-sm"
                                                        style="max-width: 90px;" min="1" max="15"
                                                        step="1" placeholder="6">
                                                    <label for="lead_interval" class="mb-0 form-label">Intervalo de
                                                        Mensagens</label>
                                                </div>
                                                <small class="text-muted"> Quantidade de Mensagens para qualificação de
                                                    leads (máx. 15).</small>
                                            </div>
                                        </div>
                                        <div class="mb-2 col-md-6">
                                            <label for="name" class="form-label">Nome do Agente</label>
                                            <div class="input-group has-validation">
                                                <span class="input-group-text"><i class="ri-user-line"></i></span>
                                                <input wire:model="name" type="text" id="name"
                                                    class="form-control" required>
                                            </div>
                                            <small class="text-muted">Nome que o agente ira se identificar, caso o
                                                usuario questione.</small>
                                        </div>
                                        <div class="mb-2 col-md-6">
                                            <label for="agent_type" class="form-label">Tipo do Agente</label>
                                            <select wire:model="agentType" id="agent_type" class="form-select"
                                                required>
                                                <option value="" selected>Selecione o tipo</option>
                                                <option value="assistente">Assistente</option>
                                                <option value="vendas">Vendas</option>
                                                <option value="suporte">Suporte</option>
                                            </select>
                                        </div>
                                        <div class="mb-2 col-md-4">
                                            <label for="personality" class="form-label">Personalidade</label>
                                            <select wire:model="personality" id="personality" class="form-select"
                                                required>
                                                <option value="" selected>Selecione a personalidade</option>
                                                <option value="formal">Formal</option>
                                                <option value="casual">Casual</option>
                                                <option value="friendly">Amigável</option>
                                                <option value="professional">Profissional</option>
                                            </select>
                                        </div>
                                        <div class="mb-2 col-md-4">
                                            <label for="gender" class="form-label">Gênero do Agente</label>
                                            <select wire:model="gender" id="gender" class="form-select">
                                                <option value="" selected>Selecione o gênero</option>
                                                <option value="masculino">Masculino</option>
                                                <option value="feminino">Feminino</option>
                                                <option value="neutro">Neutro</option>
                                            </select>
                                        </div>
                                        <div class="mb-2 col-md-4">
                                            <label for="followup_delay" class="form-label">Delay do Follow-up
                                                (minutos)</label>
                                            <select wire:model="followupDelay" id="followup_delay"
                                                class="form-select">
                                                <option value="5">5 minutos</option>
                                                <option value="10">10 minutos</option>
                                                <option value="15">15 minutos</option>
                                                <option value="30">30 minutos</option>
                                                <option value="60">1 hora</option>
                                            </select>
                                        </div>
                                        <div class="mb-2 text-end col-md-12">
                                            <div class="wizard-actions">
                                                <button type="button" class="btn btn-primary"
                                                    wire:click="setActiveTab('training-wizard')"
                                                    @if ($isProcessing) disabled @endif>
                                                    Próximo
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- ABA 2: TREINAMENTO E COMPORTAMENTO --}}
                                <div class="tab-pane fade {{ $activeTab == 'training-wizard' ? 'show active' : '' }}"
                                    id="training-wizard" role="tabpanel" aria-labelledby="training-wizard-tab">
                                    <div class="row">
                                        <div class="mb-3 col-md-12">
                                            <label for="generalTraining" class="form-label">Descrição do Nicho e Treinamento Geral</label>
                                            <textarea class="form-control"
                                                placeholder="Descreva detalhadamente o nicho do seu negócio, produtos/serviços oferecidos, público-alvo, principais características do mercado e qualquer informação relevante que ajude a treinar o agente. O sistema irá gerar automaticamente os 4 prompts principais de treinamento (Teinamento, Instruções detalhadas, FAQ e Fechamento e Qualificação de Leads) baseado nesta descrição."
                                                wire:model.defer="generalTraining" id="generalTraining" rows="8"></textarea>
                                            <small class="text-muted">Seja o mais detalhado possível. Esta descrição será usada pela IA para gerar automaticamente os prompts de treinamento completos e ricos em informações sobre o seu nicho.</small>
                                        </div>

                                        <div class="mb-3 col-md-6">
                                            <label for="negative_tags" class="form-label">Tópicos a Evitar</label>
                                            <input type="text" class="form-control" wire:model="negativeTags"
                                                placeholder="Ex: política, religião, assuntos pessoais">
                                            <small class="text-muted">Separe os tópicos por vírgula.</small>
                                        </div>

                                        <div class="mb-3 col-md-6">
                                            <label for="autonomy" class="form-label">Nível de Autonomia</label>
                                            <select wire:model="autonomy" id="autonomy" class="form-select">
                                                <option value="" selected>Selecione o nível</option>
                                                <option value="baixa">Baixa - Responde apenas quando solicitado
                                                </option>
                                                <option value="moderada">Moderada - Pede confirmação para ações
                                                    importantes</option>
                                                <option value="alta">Alta - Age proativamente</option>
                                            </select>
                                        </div>

                                        <div class="mb-3 col-md-12">
                                            <label for="conversation_endings" class="form-label">Frases de
                                                Encerramento</label>
                                            <textarea class="form-control" placeholder="Frases que o agente pode usar para encerrar conversas de forma natural."
                                                wire:model="conversationEndings" id="conversation_endings" rows="3"></textarea>
                                            <small class="text-muted">Separe as frases por linha. Ex: "Foi um prazer
                                                ajudar!", "Até logo!"</small>
                                        </div>

                                        <div class="mb-2 text-end col-md-12">
                                            <div class="wizard-actions">
                                                <button type="button" class="btn btn-secondary"
                                                    wire:click="setActiveTab('wizard-info')"
                                                    @if ($isProcessing) disabled @endif>
                                                    Anterior
                                                </button>
                                                <button type="button" class="btn btn-primary"
                                                    wire:click="setActiveTab('isFinish')"
                                                    wire:loading.attr="disabled" wire:target="setActiveTab"
                                                    @if ($isProcessing) disabled @endif>
                                                    <span wire:loading.remove wire:target="setActiveTab">
                                                        Finalizar
                                                    </span>
                                                    <span wire:loading wire:target="setActiveTab">
                                                        <span class="me-2 spinner-border spinner-border-sm" role="status"
                                                            aria-hidden="true"></span>
                                                        Processando...
                                                    </span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
