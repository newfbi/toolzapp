<meta charset="utf-8" />
<title>ToolZapp - Atendimento Automatizado WhatsApp com IA | Qualificação Automática de Leads</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<meta name="description"
    content="ToolZapp: Plataforma de atendimento automatizado para WhatsApp com qualificação automática de Leads. AIs altamente treinadas realizam atendimentos humanizados e qualificam automaticamente a tendência dos usuários. Transforme seu atendimento com inteligência artificial." />
<meta name="keywords"
    content="atendimento automatizado WhatsApp, chatbot WhatsApp, qualificação de leads, IA para WhatsApp, atendimento humanizado, automação WhatsApp, inteligência artificial atendimento, chatbot inteligente, qualificação automática leads, ToolZapp" />
<meta name="author" content="ToolZapp" />
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1" />
<meta name="language" content="Portuguese" />
<meta name="revisit-after" content="7 days" />
<meta name="rating" content="general" />

<!-- Open Graph / Facebook -->
<meta property="og:type" content="website" />
<meta property="og:url" content="{{ url()->current() }}" />
<meta property="og:title"
    content="ToolZapp - Atendimento Automatizado WhatsApp com IA | Qualificação Automática de Leads" />
<meta property="og:description"
    content="Plataforma de atendimento automatizado para WhatsApp com qualificação automática de Leads. AIs altamente treinadas realizam atendimentos humanizados e qualificam automaticamente a tendência dos usuários." />
<meta property="og:image" content="{{ asset('assets/images/logo-md-dark.png') }}" />
<meta property="og:image:width" content="1200" />
<meta property="og:image:height" content="630" />
<meta property="og:image:alt" content="ToolZapp - Atendimento Automatizado WhatsApp" />
<meta property="og:site_name" content="ToolZapp" />
<meta property="og:locale" content="pt_BR" />

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:url" content="{{ url()->current() }}" />
<meta name="twitter:title" content="ToolZapp - Atendimento Automatizado WhatsApp com IA" />
<meta name="twitter:description"
    content="Plataforma de atendimento automatizado para WhatsApp com qualificação automática de Leads. AIs altamente treinadas realizam atendimentos humanizados." />
<meta name="twitter:image" content="{{ asset('assets/images/logo-md-dark.png') }}" />
<meta name="twitter:image:alt" content="ToolZapp - Atendimento Automatizado WhatsApp" />

<!-- Canonical URL -->
<link rel="canonical" href="{{ url()->current() }}" />

<!-- Theme Color -->
<meta name="theme-color" content="#25D366" />
<meta name="msapplication-TileColor" content="#25D366" />

<!-- App favicon -->
<link rel="shortcut icon" href="{{ asset('assets/images/favicon.png') }}">
<link rel="apple-touch-icon" href="{{ asset('assets/images/favicon.png') }}">

<!-- Structured Data (JSON-LD) -->
@php
    $structuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'ToolZapp',
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'offers' => [
            '@type' => 'Offer',
            'price' => '0',
            'priceCurrency' => 'BRL',
        ],
        'description' =>
            'Plataforma de atendimento automatizado para WhatsApp com qualificação automática de Leads. AIs altamente treinadas realizam atendimentos humanizados e qualificam automaticamente a tendência dos usuários.',
        'aggregateRating' => [
            '@type' => 'AggregateRating',
            'ratingValue' => '4.8',
            'ratingCount' => '150',
        ],
        'featureList' => [
            'Atendimento automatizado para WhatsApp',
            'Qualificação automática de Leads',
            'IA altamente treinada',
            'Atendimento humanizado',
            'Análise automática de tendência dos usuários',
        ],
    ];
@endphp
<script type="application/ld+json">
{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) !!}
</script>

<!-- layout setup -->
<script type="module" src="/assets/js/layout-setup.js"></script>
