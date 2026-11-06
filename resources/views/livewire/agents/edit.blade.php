<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Editar Agente',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('dashboard'),
            ],
            [
                'label' => 'Agentes',
                'url' => route('agentes'),
            ],
            [
                'label' => 'Editar Agente',
            ],
        ],
    ])
    <!--Row-->
    <div class="row row-sm">
        @include('layouts.includes.errors-and-messages')
        @include('livewire.agents.partials.form_partial', [
            'negativeTags' => $negativeTags,
            'avatar' => $avatar,
        ])
        <!-- Row end -->
    </div>
    <!-- Row end -->
</div>

@section('js')
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="/assets/js/form/stepper.init.js"></script>
    <script src="/assets/js/app.js"></script>
    <script>
        document.addEventListener('livewire:init', function() {
            // Configurar Toast para notificações
            const Toast = Swal.mixin({
                toast: true,
                position: 'bottom-end',
                showConfirmButton: false,
                timer: 5000,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer);
                    toast.addEventListener('mouseleave', Swal.resumeTimer);
                }
            });

            // Listener para evento 'notify' do Livewire
            Livewire.on('notify', function(data) {
                const notification = data[0] || data;
                const type = notification.type || 'info';
                const message = notification.message || '';

                Toast.fire({
                    icon: type,
                    title: message,
                    timer: type === 'error' ? 7000 : 5000
                });
            });
        });
    </script>
@endsection
