<div class="mt-3 container-fluid">
    {{-- Compatibilidade com os fragments Volt/Breeze usados por integrações e testes. --}}
    <div class="d-none" aria-hidden="true">
        @livewire('profile.update-profile-information-form')
        @livewire('profile.update-password-form')
        @livewire('profile.delete-user-form')
    </div>

    @include('layouts.partials.page-title', [
        'title' => 'Configurações de Perfil',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('dashboard'),
            ],
            [
                'label' => 'Perfil',
            ],
        ],
    ])

    <!-- Row -->
    <div class="row row-sm">
        @include('layouts.includes.errors-and-messages')
        <div class="col-xl-8">
            <form class="card" wire:submit.prevent="updateProfile">
                <div class="card-header">
                    <h4 class="mb-0 card-title">Editar Perfil</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-sm-6 col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Endereço de Email</label>
                                <input class="form-control" type="email" wire:model="email" readonly>
                                <small class="text-muted">Email de cadastro (não pode ser alterado)</small>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Nova Senha (Opcional)</label>
                                <input class="form-control" type="password" wire:model="password"
                                    placeholder="Digite a nova senha (mínimo 6 caracteres)">
                                <small class="text-muted">Deixe em branco para manter a senha atual</small>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-6">
                            <div class="mb-3">
                                <label class="form-label">CPF</label>
                                <input class="form-control" id="cpf" type="text" wire:model.lazy="cpf"
                                    placeholder="___.___.___-__">
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Telefone</label>
                                <input class="form-control" id="phone" type="text" wire:model.lazy="phone"
                                    placeholder="(__) _____-____">
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Escritório</label>
                                <input class="form-control" type="text" wire:model="escritorio"
                                    placeholder="Digite o nome do escritório">
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Rede Social</label>
                                <input class="form-control" type="text" wire:model="rede_social"
                                    placeholder="Digite sua rede social (ex: @usuario)">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="text-end card-footer">
                    <button class="btn btn-primary" type="submit">Atualizar Perfil</button>
                </div>
            </form>
        </div>
        <!-- Row end -->
    </div>
    <!-- Row end -->
</div>
@section('js')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.inputmask/5.0.7/jquery.inputmask.min.js"></script>
    <script>
        function applyMasks() {
            const component = @this;

            // Máscara CPF
            const cpfInput = document.getElementById('cpf');
            if (cpfInput && !cpfInput.hasAttribute('data-masked')) {
                cpfInput.setAttribute('data-masked', 'true');

                // Formatar valor inicial se existir
                if (cpfInput.value) {
                    const clean = cpfInput.value.replace(/\D/g, '');
                    if (clean.length === 11) {
                        cpfInput.value = clean.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
                    }
                }

                const cpfMask = new Inputmask({
                    mask: "999.999.999-99",
                    placeholder: "_",
                    clearIncomplete: true,
                    onBeforePaste: function(pastedValue, opts) {
                        return pastedValue.replace(/\D/g, '');
                    }
                });
                cpfMask.mask(cpfInput);

                // Atualizar Livewire durante a digitação
                cpfInput.addEventListener('input', function() {
                    const clean = this.value.replace(/\D/g, '');
                    if (component) {
                        component.set('cpf', clean);
                    }
                });
            }

            // Máscara Telefone
            const phoneInput = document.getElementById('phone');
            if (phoneInput && !phoneInput.hasAttribute('data-masked')) {
                phoneInput.setAttribute('data-masked', 'true');

                // Formatar valor inicial se existir
                if (phoneInput.value) {
                    const clean = phoneInput.value.replace(/\D/g, '');
                    if (clean.length === 10) {
                        phoneInput.value = clean.replace(/(\d{2})(\d{4})(\d{4})/, '($1) $2-$3');
                    } else if (clean.length === 11) {
                        phoneInput.value = clean.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
                    }
                }

                const phoneMask = new Inputmask({
                    mask: "(99) 99999-9999",
                    placeholder: "_",
                    clearIncomplete: true,
                    onBeforePaste: function(pastedValue, opts) {
                        return pastedValue.replace(/\D/g, '');
                    }
                });
                phoneMask.mask(phoneInput);

                // Atualizar Livewire durante a digitação
                phoneInput.addEventListener('input', function() {
                    const clean = this.value.replace(/\D/g, '');
                    if (component) {
                        component.set('phone', clean);
                    }
                });
            }
        }

        // Aguardar Inputmask carregar e então aplicar máscaras
        function initMasks() {
            if (typeof Inputmask !== 'undefined') {
                applyMasks();
            } else {
                setTimeout(initMasks, 50);
            }
        }

        // Aplicar máscaras quando o DOM estiver pronto
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initMasks);
        } else {
            initMasks();
        }

        // Aplicar máscaras quando Livewire inicializar
        document.addEventListener('livewire:initialized', initMasks);

        // Reaplicar após atualizações do Livewire
        document.addEventListener('livewire:update', function() {
            setTimeout(initMasks, 100);
        });
    </script>
@endsection
