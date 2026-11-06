@php
    $currentYear = date('Y');
    $nextYear = date('Y-m-d', strtotime('+1 year'));
    $today = date('Y-m-d');
@endphp

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Meta Tags Básicas Otimizadas para SEO -->
    <title>Toolzapp - Agentes IA WhatsApp com Memória Persistente | Atendimento 24/7</title>
    <meta name="description"
        content="Revolucione seu atendimento com Toolzapp: Agentes de IA inteligentes para WhatsApp com memória persistente. Automação completa, aprendizado contínuo e suporte 24/7. Comece grátis!">
    <meta name="keywords"
        content="agente ia whatsapp, chatbot whatsapp, automação whatsapp, atendimento automatico ia, memoria persistente, inteligencia artificial whatsapp, assistente virtual whatsapp, toolzapp, suporte 24/7, atendimento ao cliente ia">
    <meta name="author" content="Toolzapp">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
    <meta name="language" content="pt-BR">
    <meta name="geo.region" content="BR">
    <meta name="geo.country" content="Brazil">
    <meta name="geo.placename" content="Brasil">

    <!-- Additional SEO & Authority Tags -->
    <meta name="publisher" content="Toolzapp">
    <meta name="copyright" content="© {{ $currentYear }} Toolzapp. Todos os direitos reservados.">
    <meta name="distribution" content="global">
    <meta name="rating" content="general">
    <meta name="revisit-after" content="1 days">
    <meta name="category" content="Tecnologia, IA, Automação, Atendimento ao Cliente">
    <meta name="coverage" content="Brazil">
    <meta name="target" content="business, entrepreneurs, companies, customer service">

    <!-- Security & Privacy -->
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="SAMEORIGIN">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">

    <link rel="canonical" href="{{ url()->current() }}">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="Toolzapp - Agentes IA WhatsApp com Memória Persistente | Atendimento 24/7">
    <meta property="og:description"
        content="Revolucione seu atendimento com Toolzapp: Agentes de IA inteligentes para WhatsApp com memória persistente. Automação completa, aprendizado contínuo e suporte 24/7.">
    <meta property="og:image" content="{{ asset('assets/images/logo-md.png') }}">
    <meta property="og:image:width" content="200">
    <meta property="og:image:height" content="50">
    <meta property="og:image:alt" content="Toolzapp - Plataforma de Agentes IA para WhatsApp">
    <meta property="og:site_name" content="Toolzapp">
    <meta property="og:locale" content="pt_BR">

    <!-- Twitter Cards -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="toolzapp">
    <meta name="twitter:creator" content="toolzapp">
    <meta name="twitter:title" content="Toolzapp - Agentes IA WhatsApp com Memória Persistente | Atendimento 24/7">
    <meta name="twitter:description"
        content="Revolucione seu atendimento com Toolzapp: Agentes de IA inteligentes para WhatsApp com memória persistente. Automação completa, aprendizado contínuo e suporte 24/7.">
    <meta name="twitter:image" content="{{ asset('assets/images/logo-md.png') }}">
    <meta name="twitter:image:alt" content="Toolzapp - Plataforma de Agentes IA para WhatsApp">

    <link rel="icon" href="/assets/images/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="/assets/images/favicon.png" type="image/x-icon">

    <!-- Performance & Resource Hints -->
    <link rel="dns-prefetch" href="//fonts.googleapis.com">
    <link rel="dns-prefetch" href="//fonts.gstatic.com">
    <link rel="dns-prefetch" href="//cdn.jsdelivr.net">
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>

    <!-- Preload Critical Resources -->
    <link rel="preload" href="{{ asset('assets/css/bootstrap.min.css') }}" as="style">
    <link rel="preload" href="{{ asset('assets/css/landing.css') }}" as="style">
    <link rel="preload" href="{{ asset('assets/images/logo-md.png') }}" as="image">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet">

    <!-- Remix Icon -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" type="text/css" href="/assets/css/bootstrap.min.css">

    <!-- Landing Page CSS -->
    <link rel="stylesheet" href="{{ asset('assets/css/landing.css') }}?v={{ time() }}">

    <style>
        /* Force feature-card hover effects on pricing-card */
        .pricing-card {
            position: relative !important;
            overflow: hidden !important;
        }

        .pricing-card::before {
            content: '' !important;
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 4px !important;
            background: linear-gradient(90deg, #176cb5 0%, #043158 100%) !important;
            transform: scaleX(0) !important;
            transform-origin: left !important;
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1) !important;
            z-index: 2 !important;
        }

        .pricing-card::after {
            content: '' !important;
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            background: linear-gradient(135deg, rgba(23, 108, 181, 0.03) 0%, rgba(4, 49, 88, 0.05) 100%) !important;
            opacity: 0 !important;
            transition: opacity 0.4s ease !important;
            z-index: 0 !important;
        }

        .pricing-card:hover {
            transform: translateY(-8px) !important;
            border-color: rgba(23, 108, 181, 0.5) !important;
            box-shadow: 0 8px 32px rgba(23, 108, 181, 0.25), 0 0 0 1px rgba(23, 108, 181, 0.1) !important;
        }

        .pricing-card:hover::before {
            transform: scaleX(1) !important;
        }

        .pricing-card:hover::after {
            opacity: 1 !important;
        }

        /* Pricing card icon styles matching feature-card */
        .pricing-card .pricing-icon {
            width: 64px !important;
            height: 64px !important;
            background: linear-gradient(135deg, rgba(23, 108, 181, 0.1) 0%, rgba(4, 49, 88, 0.15) 100%) !important;
            border: 1px solid rgba(23, 108, 181, 0.2) !important;
            border-radius: 12px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 0 auto 1.5rem auto !important;
            position: relative !important;
            z-index: 1 !important;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1) !important;
        }

        .pricing-card:hover .pricing-icon {
            background: linear-gradient(135deg, rgba(23, 108, 181, 0.2) 0%, rgba(4, 49, 88, 0.25) 100%) !important;
            border-color: rgba(23, 108, 181, 0.4) !important;
            transform: scale(1.05) !important;
        }

        .pricing-card .pricing-icon i {
            font-size: 1.75rem !important;
            color: #176cb5 !important;
            transition: all 0.4s ease !important;
        }

        .pricing-card:hover .pricing-icon i {
            color: #6eafd7 !important;
            transform: scale(1.1) !important;
        }

        /* Pricing card title styles matching feature-card */
        .pricing-card .pricing-title {
            font-size: 1.375rem !important;
            font-weight: 600 !important;
            margin-bottom: 1rem !important;
            color: #e0e0e8 !important;
            position: relative !important;
            z-index: 1 !important;
            transition: color 0.3s ease !important;
        }

        .pricing-card:hover .pricing-title {
            color: #6eafd7 !important;
        }

        /* Pricing card subtitle hover effect */
        .pricing-card .pricing-subtitle {
            color: #b0b0c0 !important;
            transition: color 0.3s ease !important;
        }

        .pricing-card:hover .pricing-subtitle {
            color: #d0d0d8 !important;
        }

        /* Pricing card description styles */
        .pricing-card .pricing-description p {
            color: #b8b8c8 !important;
            line-height: 1.75 !important;
            position: relative !important;
            z-index: 1 !important;
            font-size: 0.95rem !important;
            transition: color 0.3s ease !important;
        }

        .pricing-card:hover .pricing-description p {
            color: #c8c8d0 !important;
        }

        /* Pricing card benefits hover effects */
        .pricing-card .pricing-benefits h5 {
            color: #6eafd7 !important;
            transition: color 0.3s ease !important;
        }

        .pricing-card:hover .pricing-benefits h5 {
            color: #8ebfd7 !important;
        }

        .pricing-card .benefits-list li {
            color: #b8b8c8 !important;
            transition: color 0.3s ease !important;
        }

        .pricing-card:hover .benefits-list li {
            color: #c8c8d0 !important;
        }

        /* Pricing card button hover effects */
        .pricing-card .btn-pricing-primary {
            background: linear-gradient(135deg, #176cb5 0%, #043158 100%) !important;
            transition: all 0.3s ease !important;
        }

        .pricing-card .btn-pricing-primary:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 20px rgba(23, 108, 181, 0.4) !important;
            background: linear-gradient(135deg, #2a7cc1 0%, #06446c 100%) !important;
        }

        .pricing-card .btn-pricing-popular {
            background: linear-gradient(135deg, #6eafd7 0%, #176cb5 100%) !important;
        }

        .pricing-card .btn-pricing-popular:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 25px rgba(110, 167, 215, 0.4) !important;
            background: linear-gradient(135deg, #8ebfd7 0%, #2a7cc1 100%) !important;
        }

        /* Popular card enhanced effects */
        .pricing-card.pricing-popular {
            border-color: rgba(23, 108, 181, 0.6) !important;
            background: linear-gradient(180deg, rgba(23, 108, 181, 0.04) 0%, rgba(17, 17, 24) 100%) !important;
            transform: scale(1.02) !important;
            box-shadow: 0 8px 32px rgba(23, 108, 181, 0.3) !important;
        }

        .pricing-card.pricing-popular:hover {
            transform: scale(1.02) translateY(-8px) !important;
            box-shadow: 0 12px 40px rgba(23, 108, 181, 0.4), 0 0 0 1px rgba(23, 108, 181, 0.2) !important;
        }

        .pricing-card.pricing-popular::before {
            background: linear-gradient(90deg, #6eafd7 0%, #176cb5 100%) !important;
        }
    </style>

    <style>
        /* Force pricing grid layout - Critical CSS */
        .pricing-section .pricing-grid {
            display: grid !important;
            grid-template-columns: repeat(3, 1fr) !important;
            gap: 2rem !important;
            margin-top: 3rem !important;
            max-width: 1400px !important;
            margin-left: auto !important;
            margin-right: auto !important;
        }

        .pricing-section .pricing-card {
            background: #111118 !important;
            border: 1px solid #1f1f2e !important;
            border-radius: 20px !important;
            display: flex !important;
            flex-direction: column !important;
            height: 100% !important;
            padding: 0 !important;
            overflow: hidden !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4) !important;
        }

        .pricing-section .pricing-header {
            padding: 2rem 1.75rem 1.5rem !important;
            border-bottom: 1px solid #1f1f2e !important;
            text-align: center !important;
        }

        .pricing-section .pricing-price {
            padding: 2rem 2rem !important;
            text-align: center !important;
            border-bottom: 1px solid #1f1f2e !important;
            background: linear-gradient(135deg, rgba(23, 108, 181, 0.04) 0%, rgba(4, 49, 88, 0.06) 100%) !important;
            position: relative !important;
            z-index: 1 !important;
        }

        .pricing-section .pricing-card:hover .pricing-price {
            background: linear-gradient(135deg, rgba(23, 108, 181, 0.06) 0%, rgba(4, 49, 88, 0.08) 100%) !important;
        }

        .pricing-section .price-tag {
            font-size: 2rem !important;
            font-weight: 700 !important;
            background: linear-gradient(135deg, #176cb5 0%, #043158 100%) !important;
            -webkit-background-clip: text !important;
            -webkit-text-fill-color: transparent !important;
            background-clip: text !important;
            display: block !important;
            line-height: 1.1 !important;
            margin-bottom: 0.5rem !important;
            transition: transform 0.3s ease !important;
            position: relative !important;
            z-index: 1 !important;
        }

        .pricing-section .pricing-card:hover .price-tag {
            transform: scale(1.05) !important;
        }

        .pricing-section .price-tag .period {
            font-size: 1.1rem !important;
            font-weight: 400 !important;
            color: #b0b0c0 !important;
            margin-left: 0.25rem !important;
            opacity: 0.8 !important;
        }

        /* Decorative elements like stat-item */
        .pricing-section .pricing-price::before {
            content: '' !important;
            position: absolute !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            width: 120px !important;
            height: 120px !important;
            background: radial-gradient(circle, rgba(23, 108, 181, 0.08) 0%, transparent 70%) !important;
            border-radius: 50% !important;
            z-index: 0 !important;
            opacity: 0 !important;
            transition: opacity 0.4s ease !important;
        }

        .pricing-section .pricing-card:hover .pricing-price::before {
            opacity: 1 !important;
        }

        .pricing-section .pricing-price::after {
            content: '' !important;
            position: absolute !important;
            bottom: 10px !important;
            left: 50% !important;
            transform: translateX(-50%) !important;
            width: 60px !important;
            height: 2px !important;
            background: linear-gradient(90deg, transparent 0%, rgba(23, 108, 181, 0.4) 50%, transparent 100%) !important;
            border-radius: 1px !important;
            z-index: 0 !important;
        }

        .pricing-section .pricing-content {
            flex: 1 !important;
            padding: 1.75rem 1.5rem !important;
        }

        .pricing-section .pricing-action {
            padding: 1.5rem 1.75rem !important;
            border-top: 1px solid #1f1f2e !important;
        }

        @media (max-width: 1200px) {
            .pricing-section .pricing-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                max-width: 900px !important;
            }

            .pricing-section .pricing-card:last-child {
                grid-column: 1 / -1 !important;
                max-width: 450px !important;
                margin: 0 auto !important;
                width: 100% !important;
            }
        }

        @media (max-width: 968px) {
            .pricing-section .pricing-grid {
                grid-template-columns: 1fr !important;
            }

            .pricing-section .pricing-card:last-child {
                grid-column: auto !important;
                max-width: 100% !important;
            }
        }
    </style>

    @livewireStyles
</head>

<body>
    <!-- Navigation -->
    <nav class="landing-nav">
        <div class="container">
            <div class="nav-content">
                <div class="nav-logo">
                    <img src="{{ asset('/assets/images/logo-md.png') }}" alt="Toolzapp Logo" class="logo-img">
                    <span class="logo-text">Toolzapp</span>
                </div>
                <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle menu">
                    <i class="ri-menu-line"></i>
                </button>
                <div class="mobile-menu-overlay" id="mobileMenuOverlay"></div>
                <div class="nav-links" id="navLinks">
                    <a href="#recursos" class="nav-link">Recursos</a>
                    <a href="#planos" class="nav-link">Planos</a>
                    <a href="#como-funciona" class="nav-link">Como Funciona</a>
                    <a href="{{ route('login') }}" class="nav-link">Login</a>
                    <a href="{{ route('register') }}" class="btn btn-nav-cta">Começar Agora</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main>
        {{ $slot }}
    </main>

    <!-- Footer -->
    <footer class="landing-footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <div class="footer-logo">
                        <img src="{{ asset('/assets/images/logo-md.png') }}" alt="Toolzapp Logo" class="logo-img">
                        <span class="logo-text">Toolzapp</span>
                    </div>
                    <p class="footer-description">
                        Plataforma inteligente de Agentes de IA para WhatsApp com memória persistente.
                    </p>
                </div>
                <div class="footer-section">
                    <h4 class="footer-title">Produto</h4>
                    <ul class="footer-links">
                        <li><a href="#recursos">Recursos</a></li>
                        <li><a href="#planos">Planos</a></li>
                        <li><a href="#como-funciona">Como Funciona</a></li>
                        <li><a href="{{ route('register') }}">Cadastro</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4 class="footer-title">Suporte</h4>
                    <ul class="footer-links">
                        <li><a href="{{ route('login') }}">Login</a></li>
                        <li><a href="#">Documentação</a></li>
                        <li><a href="#">Contato</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4 class="footer-title">Legal</h4>
                    <ul class="footer-links">
                        <li><a href="#">Termos de Uso</a></li>
                        <li><a href="#">Política de Privacidade</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; {{ $currentYear }} Toolzapp. Todos os direitos reservados.</p>
            </div>
        </div>
    </footer>

    @livewireScripts

    <script>
        // Debug CSS Loading
        console.log('Landing CSS loaded at:', new Date().toISOString());

        // Menu Hambúrguer Mobile
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const navLinks = document.getElementById('navLinks');
            const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');
            const navLinksItems = navLinks.querySelectorAll('.nav-link, .btn-nav-cta');

            function toggleMenu() {
                navLinks.classList.toggle('active');
                mobileMenuOverlay.classList.toggle('active');
                const icon = mobileMenuToggle.querySelector('i');
                if (icon) {
                    if (navLinks.classList.contains('active')) {
                        icon.classList.remove('ri-menu-line');
                        icon.classList.add('ri-close-line');
                    } else {
                        icon.classList.remove('ri-close-line');
                        icon.classList.add('ri-menu-line');
                    }
                }
            }

            function closeMenu() {
                navLinks.classList.remove('active');
                mobileMenuOverlay.classList.remove('active');
                const icon = mobileMenuToggle.querySelector('i');
                if (icon) {
                    icon.classList.remove('ri-close-line');
                    icon.classList.add('ri-menu-line');
                }
            }

            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', toggleMenu);

                // Fechar menu ao clicar no overlay
                if (mobileMenuOverlay) {
                    mobileMenuOverlay.addEventListener('click', closeMenu);
                }

                // Fechar menu ao clicar em um link
                navLinksItems.forEach(link => {
                    link.addEventListener('click', closeMenu);
                });
            }
        });
    </script>
</body>

</html>
