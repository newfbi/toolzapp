{{-- resources/views/auth/login.blade.php --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ToolZapp - Login</title>
    <meta name="description"
        content="ToolZapp é uma plataforma inteligente de Agentes de IA para WhatsApp, ideal para atendimento automático, vendas e suporte personalizado com inteligência artificial.">
    <meta name="keywords"
        content="Agente de IA WhatsApp, chatbot WhatsApp, automação WhatsApp, atendimento automático, vendas por WhatsApp, ToolZapp, inteligência artificial, suporte via WhatsApp, assistente virtual WhatsApp">
    <meta name="author" content="NewDevelopper">
    <link rel="icon" href="/assets/images/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="/assets/images/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="/assets/css/font-awesome.css">
    <!-- Plugins css Ends-->
    <!-- Bootstrap css-->
    <link rel="stylesheet" type="text/css" href="/assets/css/bootstrap.min.css">
    <!-- App css-->
    <!-- Responsive css-->
    <link rel="stylesheet" type="text/css" href="/assets/css/responsive.css">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/login.css') }}?v={{ time() }}">
    @livewireStyles
</head>

<body>
    <div class="login-container">
        <div class="login-banner">
            <div class="pattern-container">
                {{-- Padrão tracejado será aplicado via CSS --}}
            </div>
            <div class="logo-container">
                <img src="{{ asset('/assets/images/logo-md.png') }}" alt="ToolZapp Logo" class="logo">
                <div class="brand">
                    <h1>ToolZapp</h1>
                </div>
            </div>
        </div>

        <div class="login-form">
            {{ $slot }}
        </div>
    </div>

    @livewireScripts
</body>

</html>
