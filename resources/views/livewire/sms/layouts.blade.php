<div class="mt-3 container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Temas SMS',
        'breadcrumbItems' => [['label' => 'Home', 'url' => route('dashboard')], ['label' => 'SMS'], ['label' => 'Temas SMS']],
    ])

    <div class="row row-sm">
        @include('layouts.includes.errors-and-messages')

        <div class="mb-3 col-12 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <p class="mb-0 text-muted">Crie mensagens SMS de até 5 partes concatenadas. A Infobip calcula as partes e cobra cada segmento.</p>
            <button type="button" wire:click="openCreateModal" class="btn btn-primary"><i class="me-2 ri-add-line"></i>Novo tema SMS</button>
        </div>

        <div class="mb-3 col-12 d-flex gap-2">
            <input type="text" wire:model.live.debounce.300ms="search" class="form-control" placeholder="Buscar tema ou mensagem..." style="max-width: 320px;">
            <select wire:model.live="filterStatus" class="form-select" style="max-width: 160px;">
                <option value="">Todos Status</option>
                <option value="active">Ativos</option>
                <option value="inactive">Inativos</option>
            </select>
        </div>

        @forelse ($layouts as $layout)
            <div class="mb-3 col-md-6 col-xl-4">
                <div class="card h-100 {{ $layout->status === 'active' ? '' : 'opacity-75' }}">
                    <div class="card-header d-flex align-items-center justify-content-between gap-2">
                        <h6 class="mb-0 text-truncate">{{ $layout->name }}</h6>
                        <button wire:click="toggleStatus('{{ $layout->uuid }}')" class="badge border-0 {{ $layout->status === 'active' ? 'bg-success' : 'bg-secondary' }}">
                            {{ $layout->status === 'active' ? 'Ativo' : 'Inativo' }}
                        </button>
                    </div>
                    <div class="card-body">
                        <p class="mb-2 p-2 bg-light rounded small">{{ $layout->message }}</p>
                        <small class="text-muted d-block">{{ IlluminateSupportStr::length($layout->message) }} caracteres · limite operacional de 5 partes</small>
                        @if ($layout->template_name)
                            <small class="text-muted d-block">Template: {{ $layout->template_name }}</small>
                        @endif
                    </div>
                    <div class="card-footer text-end">
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-primary" wire:click="openEditModal('{{ $layout->uuid }}')"><i class="ri-edit-line"></i></button>
                            <button type="button" class="btn btn-sm btn-info" wire:click="duplicate('{{ $layout->uuid }}')"><i class="ri-file-copy-line"></i></button>
                            <button type="button" class="btn btn-sm btn-danger" wire:click="showDeleteConfirm('{{ $layout->uuid }}', @js($layout->name))"><i class="ri-delete-bin-line"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12"><div class="py-5 text-center card"><div class="card-body">
                <i class="display-4 text-muted ri-message-2-line"></i>
                <h5 class="mt-3">Nenhum tema SMS criado</h5>
                <p class="text-muted">Crie o primeiro tema para habilitar o canal SMS no disparo.</p>
            </div></div></div>
        @endforelse

        <div class="col-12">{{ $layouts->links() }}</div>
    </div>

    <div class="modal fade" id="smsLayoutEditorModal" tabindex="-1" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">{{ $layoutId ? 'Editar tema SMS' : 'Novo tema SMS' }}</h5><button type="button" class="btn-close" wire:click="closeEditor"></button></div>
            <form wire:submit="save"><div class="modal-body">
                <div class="mb-3"><label class="form-label">Nome do tema*</label><input type="text" wire:model="name" class="form-control @error('name') is-invalid @enderror" placeholder="Ex.: Oferta de aniversário">@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="mb-3"><label class="form-label">Referência de campanha <span class="text-muted">(opcional)</span></label><input type="text" wire:model="templateName" class="form-control" placeholder="Identificador para relatórios da Infobip"></div>
                <div class="mb-2"><label class="form-label">Variáveis</label><div class="d-flex flex-wrap gap-1">@foreach ($variables as $variable)<button type="button" class="badge bg-light text-dark border" onclick="insertSmsVariable(@js('{{' . $variable['key'] . '}}'))">{{ '{{' . $variable['key'] . '}}' }}</button>@endforeach</div></div>
                <div class="mb-2"><label class="form-label">Mensagem SMS*</label><textarea id="smsMessageInput" wire:model.live="message" rows="4" maxlength="765" class="form-control @error('message') is-invalid @enderror" placeholder="Mensagem com até 5 partes concatenadas"></textarea>@error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="d-flex justify-content-between small text-muted"><span>Prévia: {{ $this->preview }}</span><span>{{ IlluminateSupportStr::length($message) }} caracteres · até 5 partes</span></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" wire:click="closeEditor">Cancelar</button><button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Salvar tema</button></div></form>
        </div></div>
    </div>
</div>

@section('js')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('livewire:init', () => {
    Livewire.on('openSmsLayoutEditor', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('smsLayoutEditorModal')).show());
    Livewire.on('closeSmsLayoutEditor', () => bootstrap.Modal.getInstance(document.getElementById('smsLayoutEditorModal'))?.hide());
    Livewire.on('showDeleteSmsLayoutConfirm', (data) => {
        const item = data[0];
        Swal.fire({title: 'Excluir tema SMS?', text: `Tem certeza que deseja excluir "${item.name}"?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Sim, excluir', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc3545'}).then(result => {
            if (result.isConfirmed) Livewire.dispatch('deleteSmsLayout', {uuid: item.id});
        });
    });
    Livewire.on('smsLayoutDeleted', message => Swal.fire({title: 'Sucesso!', text: message, icon: 'success', timer: 1800, showConfirmButton: false}));
});
function insertSmsVariable(token) {
    const input = document.getElementById('smsMessageInput');
    if (!input) return;
    const start = input.selectionStart ?? input.value.length;
    const end = input.selectionEnd ?? input.value.length;
    input.value = input.value.slice(0, start) + token + input.value.slice(end);
    input.dispatchEvent(new Event('input', {bubbles: true}));
    input.focus();
}
</script>
@endsection
