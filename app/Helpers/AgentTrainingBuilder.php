<?php

namespace App\Helpers;

use App\Models\Settings;
use App\Models\User;
use App\Support\AiModelCatalog;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenAI;

class AgentTrainingBuilder
{
    /**
     * Constrói o prompt completo do agente de forma estruturada.
     *
     * @param array $agent Dados do agente
     * @param array $settings Configurações gerais
     * @return string Prompt completo estruturado
     */
    public static function build(array $agent, array $settings = []): string
    {
        // 1. NÚCLEO COMPORTAMENTAL (Identidade, Personalidade, Autonomia)
        $behavioralCore = self::buildBehavioralCore($agent);

        // 2. REGRAS DE COMUNICAÇÃO (Limites, Formato, Restrições)
        $communicationRules = self::buildCommunicationRules($agent, $settings);

        // 3. TREINAMENTO GERADO POR IA (Training, Instructions, FAQ, Closing)
        $aiGeneratedContent = self::buildAIGeneratedContent($agent);

        // Monta o prompt final unificado
        $fullPrompt = trim(implode("\n\n", array_filter([
            $behavioralCore,
            $communicationRules,
            $aiGeneratedContent
        ])));

        return $fullPrompt;
    }

    /**
     * 1. NÚCLEO COMPORTAMENTAL
     * Define identidade, personalidade e nível de autonomia do agente.
     */
    private static function buildBehavioralCore(array $agent): string
    {
        $core = '';

        // Personalidade base
        $personality = $agent['personality'] ?? 'friendly';
        $core .= "Como um Assistente você deve agir de forma {$personality}. ";

        // Autonomia
        switch (strtolower($agent['autonomy'] ?? 'moderada')) {
            case 'alta':
                $core .= 'Você tem autonomia total para sugerir soluções, tomar decisões automáticas e antecipar as necessidades do usuário. ';
                break;
            case 'moderada':
                $core .= 'Você pode sugerir soluções e ações, mas deve sempre pedir confirmação antes de executá-las. ';
                break;
            case 'baixa':
                $core .= 'Aja de forma passiva, apenas respondendo às perguntas feitas, sem tomar iniciativas ou sugerir ações por conta própria. ';
                break;
            default:
                $core .= 'Adote um comportamento neutro em relação à autonomia. ';
                break;
        }

        // Gênero/Arquétipo (sem referências a personagens específicos)
        if (!empty($agent['gender'])) {
            $genderArchetype = self::buildGenderArchetype($agent['gender']);
            if (!empty($genderArchetype)) {
                $core .= $genderArchetype . ' ';
            }
        }

        return $core;
    }

    /**
     * Constrói arquétipo de gênero sem referências a personagens específicos.
     */
    private static function buildGenderArchetype(string $gender): string
    {
        $archetypes = [
            'feminino' => 'Você deve ter uma personalidade gentil, doce e acolhedora, com tom empático e cuidadoso.',
            'masculino' => 'Você deve ter uma personalidade cordial, educada e levemente descontraída, com tom profissional e amigável.',
            'neutro' => 'Você deve ter uma personalidade equilibrada e profissional, adaptando o tom conforme o contexto da conversa.',
            // Arquétipos especiais (sem referências a personagens)
            'mordomo_premium' => 'Você deve incorporar o arquétipo de um mordomo britânico de alto padrão, com português refinado, cordialidade exemplar e discrição profissional. Mantenha um tom respeitoso e elegante.',
        ];

        return $archetypes[strtolower($gender)] ?? '';
    }

    /**
     * 2. REGRAS DE COMUNICAÇÃO
     * Define limites, formato de resposta e restrições de conteúdo.
     */
    private static function buildCommunicationRules(array $agent, array $settings): string
    {
        $rules = '';

        // Limite de caracteres
        $maxCharacters = isset($agent['max_characters']) && $agent['max_characters'] > 0
            ? $agent['max_characters']
            : ($settings['max_characters'] ?? 300);
        $rules .= "Analise a resposta e tente obter um retorno com no máximo {$maxCharacters} caracteres. ";
        $rules .= 'Responda sempre de forma clara e objetiva. ';

        // Tópicos proibidos
        $negativeTags = $agent['negative_tags'] ?? 'Nenhum especificado';
        $rules .= "Evite os seguintes tópicos: {$negativeTags}. ";

        // Saudação personalizada
        if (!empty($agent['saudacao']) && filter_var($agent['saudacao'], FILTER_VALIDATE_BOOLEAN)) {
            if (!empty($settings['saudacao'])) {
                $rules .= $settings['saudacao'] . ' ';
            }
        }

        // Solicitação de nome do usuário
        if (!empty($agent['userName']) && filter_var($agent['userName'], FILTER_VALIDATE_BOOLEAN)) {
            if (!empty($settings['userName'])) {
                $rules .= $settings['userName'] . ' ';
            }
        }

        return $rules;
    }

    /**
     * 3. CONTEÚDO GERADO POR IA
     * Integra os campos gerados pela OpenAI (training, instructions, faq, closing)
     */
    private static function buildAIGeneratedContent(array $agent): string
    {
        $content = '';

        // Training (Treinamento detalhado)
        if (!empty($agent['training'])) {
            // Verifica se não é um placeholder
            if (!str_contains($agent['training'], 'não foi gerado automaticamente')) {
                $content .= "=== TREINAMENTO ESPECÍFICO ===\n";
                $content .= $agent['training'] . "\n\n";
            }
        }

        // Instructions (Instruções comportamentais)
        if (!empty($agent['instructions'])) {
            if (!str_contains($agent['instructions'], 'não foi gerado automaticamente')) {
                $content .= "=== INSTRUÇÕES COMPORTAMENTAIS ===\n";
                $content .= $agent['instructions'] . "\n\n";
            }
        }

        // FAQ (Perguntas frequentes)
        if (!empty($agent['faq'])) {
            if (!str_contains($agent['faq'], 'não foi gerado automaticamente')) {
                $content .= "=== PERGUNTAS FREQUENTES ===\n";
                $content .= $agent['faq'] . "\n\n";
            }
        }

        // Closing (Critérios de qualificação)
        if (!empty($agent['closing'])) {
            if (!str_contains($agent['closing'], 'não foi gerado automaticamente')) {
                $content .= "=== CRITÉRIOS DE QUALIFICAÇÃO DE LEADS ===\n";
                $content .= $agent['closing'] . "\n\n";
            }
        }

        return trim($content);
    }

    /**
     * Gera os 4 prompts principais de treinamento usando OpenAI
     *
     * @param string $generalTraining Descrição geral do nicho e necessidades
     * @param string $agentType Tipo do agente (assistente, vendas, suporte)
     * @param User|null $user Usuário para debitar créditos
     * @param array $agentConfig Configurações adicionais do agente (gender, personality, autonomy, etc.)
     * @param bool $optimized Se true, gera versão otimizada condensada (reduz tokens em ~60-70%)
     * @return array Array com os 4 prompts gerados (ou training_optimized se $optimized = true)
     */
    public static function generateTrainingPrompts(string $generalTraining, string $agentType = 'assistente', ?User $user = null, array $agentConfig = [], bool $optimized = false): array
    {
        try {
            // Aumentar timeout do PHP para evitar timeouts durante chamadas longas à OpenAI
            set_time_limit(600); // 10 minutos (alinhado com timeout do Job)
            ini_set('max_execution_time', 600);

            $client = self::getOpenAIClient();

            // Se modo otimizado, gerar versão condensada
            if ($optimized) {
                return self::generateOptimizedTrainingPrompt($client, $generalTraining, $agentType, $agentConfig, $user);
            }

            // Gerar cada prompt individualmente usando métodos separados
            $totalTokens = 0;

            $trainingResult = self::generateTrainingPrompt($client, $generalTraining, $agentType, $agentConfig);
            $training = $trainingResult['text'];
            $totalTokens += $trainingResult['tokens'];

            $instructionsResult = self::generateInstructionsPrompt($client, $generalTraining, $agentType);
            $instructions = $instructionsResult['text'];
            $totalTokens += $instructionsResult['tokens'];

            $faqResult = self::generateFaqPrompt($client, $generalTraining, $agentType);
            $faq = $faqResult['text'];
            $totalTokens += $faqResult['tokens'];

            $closingResult = self::generateClosingPrompt($client, $generalTraining, $agentType);
            $closing = $closingResult['text'];
            $totalTokens += $closingResult['tokens'];

            // Debitar créditos após gerar com sucesso (se usuário fornecido)
            if ($user && $totalTokens > 0) {
                $deductionSuccess = self::deductCredits($user, $totalTokens);
                if (!$deductionSuccess) {
                    Log::warning('Falha ao debitar créditos após gerar prompts de treinamento', [
                        'user_id' => $user->uuid,
                        'tokens_used' => $totalTokens
                    ]);
                }
            }

            return [
                'training' => trim($training),
                'instructions' => trim($instructions),
                'faq' => trim($faq),
                'closing' => trim($closing)
            ];
        } catch (\Exception $e) {
            Log::error("Erro ao gerar prompts de treinamento: {$e->getMessage()}", [
                'general_training' => substr($generalTraining, 0, 100),
                'agent_type' => $agentType,
                'error' => $e->getMessage()
            ]);

            // Retorna valores padrão em caso de erro
            return [
                'training' => 'Treinamento não foi gerado automaticamente. Por favor, preencha manualmente.',
                'instructions' => 'Instruções não foram geradas automaticamente. Por favor, preencha manualmente.',
                'faq' => 'FAQ não foi gerado automaticamente. Por favor, preencha manualmente.',
                'closing' => 'Critérios de qualificação não foram gerados automaticamente. Por favor, preencha manualmente.'
            ];
        }
    }

    /**
     * Gera versão OTIMIZADA do treinamento usando condensação inteligente via IA.
     * Reduz tokens em ~60-70% mantendo qualidade através de condensação semântica.
     * 
     * @param $client Cliente OpenAI
     * @param string $generalTraining Descrição geral do nicho
     * @param string $agentType Tipo do agente
     * @param array $agentConfig Configurações do agente
     * @param User|null $user Usuário para debitar créditos
     * @return array Array com training_optimized
     */
    private static function generateOptimizedTrainingPrompt($client, string $generalTraining, string $agentType, array $agentConfig, ?User $user = null): array
    {
        try {
            $totalTokens = 0;

            // 1. Gerar os 4 prompts normalmente
            $trainingResult = self::generateTrainingPrompt($client, $generalTraining, $agentType, $agentConfig);
            $training = $trainingResult['text'];
            $totalTokens += $trainingResult['tokens'];

            $instructionsResult = self::generateInstructionsPrompt($client, $generalTraining, $agentType);
            $instructions = $instructionsResult['text'];
            $totalTokens += $instructionsResult['tokens'];

            $faqResult = self::generateFaqPrompt($client, $generalTraining, $agentType);
            $faq = $faqResult['text'];
            $totalTokens += $faqResult['tokens'];

            $closingResult = self::generateClosingPrompt($client, $generalTraining, $agentType);
            $closing = $closingResult['text'];
            $totalTokens += $closingResult['tokens'];

            // 2. Combinar os 4 prompts
            $combinedTraining = self::combineTrainingPrompts($training, $instructions, $faq, $closing);

            // 3. Condensar com IA (reduz ~60-70%)
            $condensedResult = self::condenseTrainingWithAI($client, $combinedTraining);
            $trainingOptimized = $condensedResult['text'];
            $totalTokens += $condensedResult['tokens'];

            // Debitar créditos após gerar com sucesso (se usuário fornecido)
            if ($user && $totalTokens > 0) {
                $deductionSuccess = self::deductCredits($user, $totalTokens);
                if (!$deductionSuccess) {
                    Log::warning('Falha ao debitar créditos após gerar training otimizado', [
                        'user_id' => $user->uuid,
                        'tokens_used' => $totalTokens
                    ]);
                }
            }

            Log::info('Training otimizado gerado com sucesso', [
                'original_length' => strlen($combinedTraining),
                'optimized_length' => strlen($trainingOptimized),
                'reduction_percentage' => round((1 - strlen($trainingOptimized) / strlen($combinedTraining)) * 100, 2)
            ]);

            return [
                'training' => trim($training),
                'instructions' => trim($instructions),
                'faq' => trim($faq),
                'closing' => trim($closing),
                'training_optimized' => trim($trainingOptimized)
            ];
        } catch (\Exception $e) {
            Log::error("Erro ao gerar training otimizado: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Combina os 4 prompts em um único texto estruturado
     * 
     * @param string $training
     * @param string $instructions
     * @param string $faq
     * @param string $closing
     * @return string Texto combinado
     */
    private static function combineTrainingPrompts(string $training, string $instructions, string $faq, string $closing): string
    {
        $combined = "=== TREINAMENTO COMPLETO DO AGENTE ===\n\n";

        $combined .= "## PARTE 1: TREINAMENTO ESPECÍFICO\n\n";
        $combined .= $training . "\n\n";

        $combined .= "## PARTE 2: INSTRUÇÕES COMPORTAMENTAIS\n\n";
        $combined .= $instructions . "\n\n";

        $combined .= "## PARTE 3: PERGUNTAS FREQUENTES\n\n";
        $combined .= $faq . "\n\n";

        $combined .= "## PARTE 4: CRITÉRIOS DE QUALIFICAÇÃO\n\n";
        $combined .= $closing . "\n\n";

        return $combined;
    }

    /**
     * Condensa o treinamento usando IA (condensação semântica inteligente).
     * Reduz em ~60-70% mantendo essência, regras principais e fluxo.
     * 
     * @param $client Cliente OpenAI
     * @param string $combinedTraining Treinamento completo combinado
     * @return array ['text' => string, 'tokens' => int]
     */
    private static function condenseTrainingWithAI($client, string $combinedTraining): array
    {
        $systemPrompt = "Você é um especialista em otimização de prompts para agentes de IA. Sua tarefa é condensar o treinamento de um agente mantendo TODA a qualidade e essência, mas reduzindo o tamanho em aproximadamente 60-70%.

INSTRUÇÕES CRÍTICAS:
1. MANTENHA OBRIGATORIAMENTE:
   - Identidade completa do agente (quem é, qual empresa, qual papel)
   - Personalidade e tom de comunicação
   - TODAS as regras principais de comunicação no WhatsApp
   - Fluxo de atendimento (pode resumir etapas, mas manter sequência lógica)
   - Pontos críticos de cada seção
   - Diretrizes de qualificação de leads
   - Instruções sobre valores, descontos e objeções

2. REMOVA/REDUZA:
   - Exemplos excessivos (manter apenas 1-2 mais representativos por seção)
   - Repetições e redundâncias
   - Explicações muito longas (tornar mais diretas)
   - Formatação excessiva (manter estrutura, mas simplificar)

3. PRESERVE O TOM:
   - Manter linguagem natural e adaptada ao WhatsApp
   - Manter foco em conversão e ação
   - Manter caráter humanizado (não parecer chatbot)

4. ESTRUTURA DO RESULTADO:
   - Manter seções principais identificáveis
   - Usar bullets e numeração quando apropriado
   - Ser CONCISO mas COMPLETO

IMPORTANTE: O resultado deve ser um prompt FUNCIONAL e COMPLETO, apenas mais conciso. Não perca informações críticas.

Responda APENAS com o treinamento condensado, sem introduções ou explicações adicionais.";

        $userPrompt = "Condense este treinamento de agente em aproximadamente 60-70% do tamanho original, mantendo toda a qualidade:\n\n{$combinedTraining}\n\nTREINAMENTO CONDENSADO:";

        return self::generateTextWithTokens($client, $systemPrompt . "\n\n" . $userPrompt);
    }


    /**
     * Gera o prompt de TRAINING (Treinamento detalhado e completo)
     * 
     * @param $client Cliente OpenAI
     * @param string $generalTraining Descrição geral do nicho
     * @param string $agentType Tipo do agente
     * @param array $agentConfig Configurações do agente (gender, personality, autonomy, etc.)
     * @return array ['text' => string, 'tokens' => int]
     */
    private static function generateTrainingPrompt($client, string $generalTraining, string $agentType, array $agentConfig = []): array
    {
        $typeDescription = self::getTypeDescription($agentType);

        // Construir contexto de configuração do agente
        $configContext = self::buildAgentConfigContext($agentConfig);

        $prompt = "Você é um especialista em criar treinamentos detalhados e estruturados para agentes de inteligência artificial que devem atuar de forma humanizada e natural NO WHATSAPP.

        CONTEXTO IMPORTANTE - WhatsApp:
        - Este agente atua exclusivamente via WhatsApp, um canal de comunicação pessoal e direto
        - WhatsApp é um ambiente de mensagens rápidas, objetivas e naturais
        - As pessoas esperam conversas mais pessoais e menos formais
        - Respostas devem ser curtas, diretas e focadas em ação, não em explicações longas
        - Evite parecer chatbot genérico ou IA informativa - seja mais humano e próximo
        - Linguagem deve ser natural, coloquial (sem ser vulgar) e adaptada ao WhatsApp
        - Evite textos muito longos, listas excessivas ou explicações técnicas demais
        - Foque em diálogo fluido e natural, como se estivesse conversando com um amigo/colega do negócio

        Baseado na seguinte descrição do nicho e necessidades: {$generalTraining}

        Tipo de agente: {$typeDescription}
        
        {$configContext}

        Crie um treinamento DETALHADO, ESTRUTURADO e COMPLETO (mínimo 500 palavras) seguindo EXATAMENTE esta estrutura:
        1. TÍTULO/IDENTIFICAÇÃO (no início):
        - Comece com: \"Você é [papel/função] do [empresa/departamento]\"
        - Identifique claramente o papel e a empresa/negócio
        - Seu papel é [descrição específica do que o agente faz]
        2. OBJETIVO (seção clara):
        - Defina o objetivo principal do atendimento
        - Explique o que o atendimento deve gerar (confiança, segurança, clareza, etc.)
        - Mencione o que deve evitar (linguagem técnica excessiva, promessas irreais, etc.)
        3. DIRETRIZES DE COMUNICAÇÃO NO WHATSAPP (lista com bullets, MUITO IMPORTANTE):
        - Fale como uma pessoa REAL conversando no WhatsApp, não como chatbot genérico
        - Use frases CURTAS e DIRETAS (WhatsApp é para mensagens rápidas, não textos longos)
        - Evite explicações muito detalhadas ou informativas demais - seja objetivo e prático
        - Use linguagem NATURAL e COLOQUIAL, adaptada ao WhatsApp (sem ser vulgar)
        - Use emojis com moderação apenas no início, se já estiver no contexto inicial
        - NUNCA diga que é robô, assistente, IA ou chatbot - seja uma pessoa do time
        - Sempre utilize o nome do cliente após identificá-lo (use variável {{nome}})
        - Não prometa resultados impossíveis ou prazos exatos sem validação
        - Seja empático, respeitoso e sem julgamentos, como em uma conversa pessoal
        - Conduza a conversa de forma NATURAL e FLUIDA, sem parecer script automatizado
        - Priorize esclarecer dúvidas antes de tentar qualquer conversão
        - Evite respostas genéricas tipo 'como posso ajudar?' - seja específico e direto
        - Foque em DIÁLOGO, não em monólogos informativos
        - Adicione outras diretrizes específicas para este nicho
        4. FLUXO DE ATENDIMENTO (passo a passo numerado, OBRIGATÓRIO):
        - Crie um fluxo detalhado e numerado (1, 2, 3, etc.) com:
            * Cada etapa do atendimento
            * Exemplos práticos de falas/textos que o agente deve usar
            * Quando aguardar respostas do cliente
            * Como adaptar o tom conforme a situação
            * Uso de variáveis como {{nome}} quando apropriado
            * Direcionamentos claros para próximos passos
        - Inclua etapas como: Início do atendimento, Acolhimento, Exploração da situação, Esclarecimento, Direcionamento, Encerramento
        - Para cada etapa, forneça exemplos concretos de falas/textos
        5. INSTruções sobre o nicho específico:
        - Como explicar o serviço/produto de forma clara
        - Principais objeções e como lidar com elas
        - Sinais de interesse que o agente deve identificar
        IMPORTANTE PARA WHATSAPP:
        - O treinamento deve ser HUMANIZADO, NATURAL e ADAPTADO AO WHATSAPP
        - Evite criar respostas genéricas ou muito informativas como IAs tradicionais
        - Use exemplos práticos e concretos de falas CURTAS e DIRETAS
        - Estruture com títulos em maiúsculas, seções numeradas e bullets
        - Seja específico sobre como o agente deve se comportar em cada situação NO WHATSAPP
        - Inclua variáveis como {{nome}} quando apropriado
        - O fluxo deve ser detalhado e prático, com exemplos reais de mensagens WhatsApp
        - Priorize CONVERSA e AÇÃO, não explicações longas
        - Lembre-se: WhatsApp é para diálogo rápido, não para textos informativos extensos
        Responda APENAS com o conteúdo do treinamento estruturado, sem introduções ou explicações adicionais. Comece diretamente com o título/identificação.";

        return self::generateTextWithTokens($client, $prompt);
    }

    /**
     * Gera o prompt de INSTRUCTIONS (Instruções comportamentais comerciais)
     * 
     * @param $client Cliente OpenAI
     * @param string $generalTraining Descrição geral do nicho
     * @param string $agentType Tipo do agente
     * @param array $agentConfig Configurações do agente
     * @return array ['text' => string, 'tokens' => int]
     */
    private static function generateInstructionsPrompt($client, string $generalTraining, string $agentType): array
    {
        $typeDescription = self::getTypeDescription($agentType);

        $prompt = "Você é um especialista em criar instruções comportamentais comerciais e estratégicas para agentes de IA que devem conduzir conversas NO WHATSAPP com foco em conversão/vendas.

        CONTEXTO IMPORTANTE - WhatsApp:
        - Este agente atua via WhatsApp, canal de comunicação pessoal e direto
        - WhatsApp exige respostas curtas, objetivas e naturais
        - Evite parecer chatbot genérico - seja humano e próximo
        - Foque em ação e diálogo, não em explicações extensas
        - Linguagem natural e adaptada ao WhatsApp

        Baseado na seguinte descrição do nicho: {$generalTraining}

        Tipo de agente: {$typeDescription}

        Crie instruções ADICIONAIS, ESPECÍFICAS e COMERCIAIS (mínimo 500 palavras) sobre como o agente deve se comportar em situações específicas NO WHATSAPP, seguindo EXATAMENTE esta estrutura numerada:
        1. POSTURA COMERCIAL ATIVA:
        - Como conduzir o atendimento de forma consultiva, mas com foco claro em conversão
        - O agente não deve apenas informar, mas conduzir o cliente para o próximo passo
        - Como evitar respostas neutras demais ou muito longas
        - Sempre que possível, avançar a conversa para análise ou encaminhamento
        - Inclua exemplos práticos de como conduzir a conversa de forma ativa
        2. QUANDO O CLIENTE DEMONSTRAR DOR OU FRUSTRAÇÃO:
        - Use validação emocional ANTES de vender
        - Como mostrar que entende o impacto da situação no dia a dia do cliente
        - Após validar a dor, apresente a solução de forma direta
        - Como mostrar que existe um caminho real para resolver a situação
        - Forneça um EXEMPLO PRÁTICO DE ABORDAGEM completo e pronto para usar
        - Especifique qual é o foco do serviço/produto (o que oferecer e o que NÃO oferecer)
        3. QUANDO O CLIENTE PERGUNTAR SOBRE VALORES:
        - NUNCA diga valores exatos sem contexto
        - Como explicar que o valor depende da análise/contexto específico
        - Como informar que existe um teto aproximado ou faixa de valores (se aplicável)
        - Crie um MODELO DE RESPOSTA OBRIGATÓRIA completa e pronta para usar
        - Após informar, SEMPRE avançar para análise ou encaminhamento
        - Forneça exemplo de como avançar após informar valores
        4. QUANDO O CLIENTE HESITAR POR PREÇO:
        - Como reforçar custo-benefício, NÃO o valor isolado
        - Como comparar o investimento com os benefícios reais específicos deste nicho
        - Nunca discutir preço sem reforçar valor
        - Forneça um EXEMPLO PRÁTICO de abordagem para esta situação
        5. QUANDO O CLIENTE PEDIR DESCONTO OU BARGANHAR:
        - NÃO prometer descontos
        - Como reforçar que as condições são definidas após análise
        - Como mostrar que o foco é encontrar a melhor solução, não apenas baixar preço
        - Forneça um EXEMPLO PRÁTICO de resposta para esta situação
        6. QUANDO O CLIENTE DESCONFIAR OU DEMONSTRAR MEDO DE GOLPE:
        - Como agir com firmeza e transparência
        - Como explicar que a análise é clara e nada é feito sem explicação e consentimento
        - Como mostrar profissionalismo e segurança
        - Forneça um EXEMPLO PRÁTICO de resposta para esta situação
        7. QUANDO O CLIENTE ESTIVER MUITO CURIOSO, MAS SEM DECISÃO:
        - NÃO deixar a conversa esfriar
        - Como transformar curiosidade em ação
        - Use frases que induzam o próximo passo de forma natural
        - Forneça um EXEMPLO PRÁTICO de abordagem para esta situação
        8. QUANDO IDENTIFICAR LEAD QUENTE:
        - Liste os SINAIS ESPECÍFICOS que indicam lead quente para este nicho (ex: perguntar sobre valores, falar em urgência, mencionar situação recente, demonstrar alívio ao entender o serviço)
        - Como agir IMEDIATAMENTE para encaminhar ao consultor/especialista
        - NÃO prolongar a conversa desnecessariamente quando identificar lead quente
        - Forneça um EXEMPLO PRÁTICO de resposta para encaminhar lead quente
        9. CONTROLE DE RITMO DO ATENDIMENTO:
        - Evitar longas explicações técnicas
        - Preferir respostas objetivas com direcionamento
        - Sempre que responder uma dúvida, incluir um passo seguinte implícito ou explícito
        - Como manter a conversa em movimento constante
        10. ENCERRAMENTO COM DIRECIONAMENTO CLARO:
        - NUNCA finalizar a conversa sem deixar claro o próximo passo
        - O encerramento deve reforçar continuidade e ação
        - Forneça um MODELO PRÁTICO de encerramento com direcionamento claro

        IMPORTANTE - CARACTERÍSTICAS OBRIGATÓRIAS PARA WHATSAPP:
        - Cada seção numerada deve ter instruções CLARAS e ESPECÍFICAS para WhatsApp
        - Forneça EXEMPLOS PRÁTICOS de mensagens WhatsApp CURTAS e DIRETAS
        - Use variável {{nome}} nos exemplos quando apropriado
        - Foque em CONVERSÃO e AVANÇO da conversa, não apenas em informar
        - Seja específico sobre protocolos e procedimentos comerciais NO WHATSAPP
        - Inclua o que NÃO fazer (ex: não prometer descontos, não dar valores exatos sem contexto)
        - As instruções devem ser AÇÃO-ORIENTADAS e COMERCIAIS
        - Evite respostas genéricas - seja específico e direto como em uma conversa WhatsApp
        - Priorize diálogo natural, não explicações longas ou informativas demais
        Responda APENAS com as instruções numeradas (1, 2, 3...) estruturadas, sem introduções ou explicações adicionais. Comece diretamente com o número 1.";

        return self::generateTextWithTokens($client, $prompt);
    }

    /**
     * Gera o prompt de FAQ (Perguntas frequentes)
     * 
     * @param $client Cliente OpenAI
     * @param string $generalTraining Descrição geral do nicho
     * @param string $agentType Tipo do agente
     * @param array $agentConfig Configurações do agente
     * @return array ['text' => string, 'tokens' => int]
     */
    private static function generateFaqPrompt($client, string $generalTraining, string $agentType): array
    {
        $typeDescription = self::getTypeDescription($agentType);

        $prompt = "Você é um especialista em identificar dúvidas comuns e criar respostas úteis para nichos de mercado.
        Baseado na seguinte descrição do nicho: {$generalTraining}
        Tipo de agente: {$typeDescription}
        
        Crie um FAQ COMPLETO com ATÉ 10 das principais dúvidas e suas respostas detalhadas sobre este nicho específico.
        FORMATO OBRIGATÓRIO:
        P: [Pergunta]
        R: [Resposta completa e útil]
        DIRETRIZES PARA AS PERGUNTAS:
        - Devem ser as dúvidas MAIS FREQUENTES que os clientes têm sobre este nicho
        - Devem ser RELEVANTES e PRÁTICAS
        - Devem cobrir diferentes aspectos: funcionamento, valores, prazos, benefícios, processo, etc.
        - Devem estar em linguagem natural, como os clientes realmente perguntam
        DIRETRIZES PARA AS RESPOSTAS:
        - Devem ser COMPLETAS e DETALHADAS (mínimo 3-4 linhas cada)
        - Devem ser ESPECÍFICAS para este nicho, não genéricas
        - Devem ser ÚTEIS e PRÁTICAS
        - Devem usar linguagem CLARA e ACESSÍVEL
        - Devem ser humanizadas, como se uma pessoa real estivesse respondendo
        - Devem evitar jargões técnicos desnecessários
        - Podem incluir exemplos práticos quando apropriado
        IMPORTANTE:
        - Priorize dúvidas que os clientes REALMENTE têm sobre este negócio específico
        - As respostas devem ajudar o agente a responder de forma precisa e confiável
        - Mantenha consistência no tone e estilo
        - Seja específico sobre valores, prazos, processos, quando relevante para este nicho
        Responda APENAS com o FAQ no formato especificado (P: ... R: ...), sem introduções, numeração ou explicações adicionais.";

        return self::generateTextWithTokens($client, $prompt);
    }

    /**
     * Gera o prompt de CLOSING (Critérios de qualificação de leads)
     * 
     * @param $client Cliente OpenAI
     * @param string $generalTraining Descrição geral do nicho
     * @param string $agentType Tipo do agente
     * @param array $agentConfig Configurações do agente
     * @return array ['text' => string, 'tokens' => int]
     */
    private static function generateClosingPrompt($client, string $generalTraining, string $agentType): array
    {
        $typeDescription = self::getTypeDescription($agentType);

        $prompt = "Você é um especialista em qualificação de leads e vendas com foco em identificar oportunidades de conversão.
        Baseado na seguinte descrição do nicho: {$generalTraining}
        Tipo de agente: {$typeDescription}
        
        Descreva os CRITÉRIOS E MÉTRICAS DETALHADOS (mínimo 300 palavras) que o agente deve usar para identificar e qualificar leads, seguindo esta estrutura:
        1. INTERESSE DEMONSTRADO:
        - Como identificar sinais de interesse (palavras-chave, perguntas específicas, demonstração de necessidade)
        - Níveis de interesse (alto, médio, baixo) e como classificá-los
        - Exemplos práticos de respostas que indicam interesse real
        2. ORÇAMENTO DISPONÍVEL:
        - Como avaliar capacidade de investimento sem ser invasivo
        - Perguntas que ajudam a identificar poder aquisitivo
        - Como identificar se o cliente tem recursos para o serviço/produto
        - Sinais que indicam disponibilidade financeira
        3. URGÊNCIA:
        - Como identificar necessidade imediata vs interesse futuro
        - Sinais de urgência (prazo, situação crítica, necessidade imediata)
        - Como priorizar leads com maior urgência
        - Exemplos de situações que indicam urgência
        4. AUTORIDADE PARA DECISÃO:
        - Como identificar se o cliente tem poder de decisão
        - Perguntas sutis para verificar autoridade
        - Sinais que indicam que o cliente pode decidir sozinho
        - Como lidar quando o cliente precisa consultar terceiros
        5. FIT DO PRODUTO/SERVIÇO:
        - Como avaliar se o produto/serviço se adequa à necessidade do cliente
        - Critérios de compatibilidade específicos deste nicho
        - Como identificar se o cliente é o público-alvo ideal
        - Sinais que indicam boa compatibilidade
        6. OUTROS CRITÉRIOS ESPECÍFICOS DO NICHO:
        - Critérios adicionais relevantes para este negócio específico
        - Fatores únicos que devem ser considerados
        - Métricas específicas do mercado/segmento
        7. MOMENTO IDEAL PARA CONVERSÃO:
        - Como identificar o momento certo para direcionar para conversão
        - Sinais que indicam que o lead está pronto
        - Como não perder a oportunidade sem ser invasivo
        - Protocolo para encaminhamento efetivo
        IMPORTANTE:
        - Seja específico sobre sinais, palavras-chave e comportamentos a observar
        - Forneça exemplos práticos de perguntas e respostas que indicam cada critério
        - Inclua métricas práticas e acionáveis
        - Considere as particularidades deste nicho específico
        Responda APENAS com os critérios e métricas estruturados, sem introduções ou explicações adicionais.";

        return self::generateTextWithTokens($client, $prompt);
    }

    /**
     * Retorna a descrição do tipo de agente
     * 
     * @param string $agentType Tipo do agente
     * @return string Descrição do tipo
     */
    private static function getTypeDescription(string $agentType): string
    {
        $typeDescriptions = [
            'assistente' => 'assistente virtual estratégico que conduz conversas consultivas no WhatsApp, identifica necessidades do cliente através de perguntas direcionadas, qualifica leads de forma natural e encaminha oportunidades quentes para conversão. Seu foco é criar conexão genuína, esclarecer dúvidas de forma objetiva e avançar a conversa para próximos passos concretos (análise, agendamento, encaminhamento). Não é um chatbot passivo que apenas responde - é um consultor proativo que guia o cliente na jornada',
            'vendas' => 'agente comercial focado em conversão que atua no WhatsApp com postura consultiva mas assertiva. Seu objetivo principal é VENDER e CONVERTER leads em clientes. Identifica dores e necessidades rapidamente, apresenta soluções de forma direta e persuasiva, contorna objeções com técnicas comerciais, reforça valor e benefícios constantemente, cria senso de urgência quando apropriado e conduz o cliente decisivamente para o fechamento. Não apenas informa sobre produtos/serviços - VENDE ativamente, usando gatilhos mentais, validação emocional e técnicas de persuasão para avançar cada conversa rumo à conversão',
            'suporte' => 'agente de suporte técnico que resolve problemas de forma rápida e eficiente no WhatsApp, mas com visão comercial estratégica. Além de solucionar dúvidas e questões técnicas com clareza e empatia, identifica oportunidades de upsell, cross-sell e qualificação de leads durante o atendimento. Transforma problemas em oportunidades, usa a resolução de dificuldades para criar confiança e, quando apropriado, apresenta soluções adicionais ou encaminha para o time comercial. Não é apenas reativo - é proativo em antecipar necessidades e oferecer valor adicional'
        ];

        return $typeDescriptions[$agentType] ?? $typeDescriptions['assistente'];
    }

    /**
     * Constrói contexto de configuração do agente para incluir nos prompts
     * 
     * @param array $agentConfig Configurações do agente
     * @return string Contexto formatado
     */
    private static function buildAgentConfigContext(array $agentConfig): string
    {
        if (empty($agentConfig)) {
            return '';
        }

        $context = "CONFIGURAÇÕES DO AGENTE:\n";

        // Gênero/Tom
        if (!empty($agentConfig['gender'])) {
            $genderMap = [
                'masculino' => 'Tom masculino: cordial, educado e levemente descontraído',
                'feminino' => 'Tom feminino: gentil, doce e acolhedor',
                'neutro' => 'Tom neutro: equilibrado e profissional'
            ];
            $context .= "- Gênero/Tom: " . ($genderMap[$agentConfig['gender']] ?? $agentConfig['gender']) . "\n";
        }

        // Personalidade
        if (!empty($agentConfig['personality'])) {
            $personalityMap = [
                'formal' => 'Formal e profissional',
                'casual' => 'Casual e descontraído',
                'friendly' => 'Amigável e acolhedor',
                'professional' => 'Profissional e objetivo'
            ];
            $context .= "- Personalidade: " . ($personalityMap[$agentConfig['personality']] ?? $agentConfig['personality']) . "\n";
        }

        // Autonomia
        if (!empty($agentConfig['autonomy'])) {
            $autonomyMap = [
                'alta' => 'Alta (pode tomar decisões e sugerir ações proativamente)',
                'moderada' => 'Moderada (sugere mas pede confirmação)',
                'baixa' => 'Baixa (apenas responde, não toma iniciativas)'
            ];
            $context .= "- Autonomia: " . ($autonomyMap[$agentConfig['autonomy']] ?? $agentConfig['autonomy']) . "\n";
        }

        // Limite de caracteres
        if (!empty($agentConfig['max_characters'])) {
            $context .= "- Limite de caracteres por resposta: {$agentConfig['max_characters']} caracteres\n";
        }

        // Tópicos proibidos
        if (!empty($agentConfig['negative_tags'])) {
            $context .= "- Tópicos a evitar: {$agentConfig['negative_tags']}\n";
        }

        $context .= "\nIMPORTANTE: O treinamento gerado deve estar ALINHADO com essas configurações.\n";

        return $context;
    }

    /**
     * Verifica se o usuário tem créditos suficientes para gerar prompts de treinamento
     *
     * @param User $user Usuário a verificar
     * @param int $estimatedCredits Créditos estimados necessários (padrão: 8)
     * @return bool
     */
    public static function hasEnoughCredits(User $user, int $estimatedCredits = 8): bool
    {
        if (!$user) {
            return false;
        }

        $totalCredits = ($user->credits ?? 0) + ($user->combo ?? 0);
        return $totalCredits >= $estimatedCredits;
    }

    /**
     * Debita créditos do usuário após gerar prompts
     *
     * @param User $user Usuário
     * @param int $tokensUsed Total de tokens utilizados
     * @return bool True se débito foi realizado com sucesso
     */
    public static function deductCredits(User $user, int $tokensUsed): bool
    {
        if (!$user) {
            Log::error('Tentativa de debitar créditos de usuário inexistente', [
                'tokens_used' => $tokensUsed
            ]);
            return false;
        }

        // Calcular créditos a serem debitados (tokensUsed / 1000)
        $creditsToDeduct = $tokensUsed / 1000;

        try {
            return DB::transaction(function () use ($user, $tokensUsed, $creditsToDeduct) {
                // Bloquear o registro do usuário para evitar condições de corrida
                $userWithLock = User::where('uuid', $user->uuid)
                    ->lockForUpdate()
                    ->first();

                if (!$userWithLock) {
                    Log::error('Usuário não encontrado durante debitação de créditos', [
                        'user_uuid' => $user->uuid,
                        'tokens_used' => $tokensUsed
                    ]);
                    return false;
                }

                // Calcular saldo total disponível
                $totalCredits = ($userWithLock->credits ?? 0) + ($userWithLock->combo ?? 0);

                // Verificar se usuário tem saldo suficiente
                if ($totalCredits < $creditsToDeduct) {
                    Log::error('Usuário não tem créditos suficientes para a operação', [
                        'user_id' => $userWithLock->uuid,
                        'total_credits' => $totalCredits,
                        'credits_needed' => $creditsToDeduct,
                        'tokens_used' => $tokensUsed
                    ]);
                    return false;
                }

                // Debitar do combo primeiro
                $comboUsed = 0;
                $creditsUsed = 0;

                if ($userWithLock->combo >= $creditsToDeduct) {
                    $userWithLock->decrement('combo', $creditsToDeduct);
                    $comboUsed = $creditsToDeduct;
                } else {
                    // Usar todo o combo disponível
                    $comboUsed = $userWithLock->combo;
                    $userWithLock->update(['combo' => 0]);

                    // Completar com credits
                    $remainingCredits = $creditsToDeduct - $comboUsed;
                    if ($remainingCredits > 0) {
                        $userWithLock->decrement('credits', $remainingCredits);
                        $creditsUsed = $remainingCredits;
                    }
                }

                Log::info('Créditos debitados - geração de prompts de treinamento', [
                    'user_id' => $userWithLock->uuid,
                    'tokens_used' => $tokensUsed,
                    'credits_deducted' => $creditsToDeduct,
                    'combo_used' => $comboUsed,
                    'credits_used' => $creditsUsed,
                    'remaining_combo' => $userWithLock->combo,
                    'remaining_credits' => $userWithLock->credits
                ]);

                return true;
            });
        } catch (\Exception $e) {
            Log::error('Erro ao debitar créditos do usuário', [
                'user_id' => $user->uuid,
                'tokens_used' => $tokensUsed,
                'credits_to_deduct' => $creditsToDeduct,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Obtém cliente OpenAI configurado usando API key do sistema
     */
    private static function getOpenAIClient()
    {
        $settings = Settings::first();

        if (!$settings || empty($settings->api_key)) {
            throw new \Exception('API key do sistema não configurada para OpenAI.');
        }

        $httpClient = new Client([
            'timeout' => 180, // 3 minutos - tempo necessário para múltiplas chamadas à OpenAI
            'connect_timeout' => 30,
            'verify' => config('services.http.verify_ssl', true)
        ]);

        return OpenAI::factory()
            ->withApiKey($settings->api_key)
            ->withHttpClient($httpClient)
            ->make();
    }

    /**
     * Gera texto usando OpenAI Chat Completions e retorna texto + tokens
     *
     * @param $client Cliente OpenAI
     * @param string $prompt Prompt a ser enviado
     * @return array ['text' => string, 'tokens' => int]
     */
    private static function generateTextWithTokens($client, string $prompt): array
    {
        try {
            $response = $client->chat()->create([
                'model' => AiModelCatalog::default(),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_completion_tokens' => 16000,
            ]);

            $text = trim($response->choices[0]->message->content);
            $usage = $response->usage;
            $tokens = $usage->totalTokens ?? 0;

            return [
                'text' => $text,
                'tokens' => $tokens
            ];
        } catch (\Exception $e) {
            Log::error("Erro ao gerar texto com OpenAI: {$e->getMessage()}");
            throw $e;
        }
    }
}
