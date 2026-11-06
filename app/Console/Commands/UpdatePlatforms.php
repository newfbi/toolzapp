<?php

namespace App\Console\Commands;

use App\Models\Platform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdatePlatforms extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'platforms:update {--fresh : Recriar todas as plataformas}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Atualizar plataformas de IA com modelos mais recentes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('fresh')) {
            $this->info('Removendo todas as plataformas existentes...');
            Platform::query()->delete();
        }

        $platforms = $this->getPlatformsData();

        $this->info('Atualizando plataformas...');

        $progressBar = $this->output->createProgressBar(count($platforms));
        $progressBar->start();

        foreach ($platforms as $platformData) {
            Platform::updateOrCreate(
                ['slug' => $platformData['slug']],
                $platformData
            );
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('✅ Plataformas atualizadas com sucesso!');
        $this->info('Total de plataformas: ' . Platform::count());

        return Command::SUCCESS;
    }

    /**
     * Retorna os dados das plataformas
     */
    private function getPlatformsData(): array
    {
        return [
            [
                'name' => 'OpenAI',
                'slug' => 'openai',
                'models' => [
                    'gpt-6.1-sol' => 'GPT-6.1 Sol',
                    'gpt-6-luna' => 'GPT-6 Luna',
                ],
                'status' => 'active',
                'api_base_url' => 'https://api.openai.com/v1',
                'api_key_required' => true,
                'rate_limits' => [
                    'requests_per_minute' => 60,
                    'tokens_per_minute' => 40000
                ],
                'supported_features' => ['chat', 'image', 'audio', 'speech'],
                'description' => 'Plataforma líder em IA generativa com modelos avançados de linguagem e multimídia.',
                'is_active' => true,
                'priority' => 1
            ],
            [
                'name' => 'Anthropic Claude',
                'slug' => 'claude',
                'models' => [
                    'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet (mais avançado)',
                    'claude-3-5-haiku-20241022' => 'Claude 3.5 Haiku (rápido e econômico)',
                    'claude-3-opus-20240229' => 'Claude 3 Opus',
                    'claude-3-sonnet-20240229' => 'Claude 3 Sonnet',
                    'claude-3-haiku-20240307' => 'Claude 3 Haiku',
                    'claude-2.1' => 'Claude 2.1',
                    'claude-2' => 'Claude 2',
                    'claude-instant-1.2' => 'Claude Instant 1.2'
                ],
                'status' => 'active',
                'api_base_url' => 'https://api.anthropic.com',
                'api_key_required' => true,
                'rate_limits' => [
                    'requests_per_minute' => 50,
                    'tokens_per_minute' => 25000
                ],
                'supported_features' => ['chat'],
                'description' => 'IA focada em segurança e alinhamento ético, conhecida pela qualidade de resposta.',
                'is_active' => true,
                'priority' => 2
            ],
            [
                'name' => 'Google Gemini',
                'slug' => 'gemini',
                'models' => [
                    'gemini-1.5-pro' => 'Gemini 1.5 Pro (mais avançado)',
                    'gemini-1.5-flash' => 'Gemini 1.5 Flash (rápido)',
                    'gemini-1.0-pro' => 'Gemini 1.0 Pro',
                    'gemini-pro' => 'Gemini Pro',
                    'gemini-pro-vision' => 'Gemini Pro Vision',
                    'palm-2' => 'PaLM 2',
                    'text-bison' => 'Text Bison',
                    'chat-bison' => 'Chat Bison'
                ],
                'status' => 'active',
                'api_base_url' => 'https://generativelanguage.googleapis.com',
                'api_key_required' => true,
                'rate_limits' => [
                    'requests_per_minute' => 60,
                    'requests_per_day' => 1500
                ],
                'supported_features' => ['chat', 'image', 'multimodal'],
                'description' => 'IA multimodal do Google, integrada com serviços do Google Cloud.',
                'is_active' => true,
                'priority' => 3
            ],
            [
                'name' => 'Groq',
                'slug' => 'groq',
                'models' => [
                    'llama2-70b-4096' => 'Llama 2 70B',
                    'llama2-7b-2048' => 'Llama 2 7B',
                    'mixtral-8x7b-32768' => 'Mixtral 8x7B',
                    'gemma-7b-it' => 'Gemma 7B',
                    'llama3-8b-8192' => 'Llama 3 8B',
                    'llama3-70b-8192' => 'Llama 3 70B',
                    'llama3.1-8b-instant' => 'Llama 3.1 8B Instant',
                    'llama3.1-70b-versatile' => 'Llama 3.1 70B Versatile',
                    'llama3.1-405b-instruct' => 'Llama 3.1 405B Instruct'
                ],
                'status' => 'active',
                'api_base_url' => 'https://api.groq.com/openai/v1',
                'api_key_required' => true,
                'rate_limits' => [
                    'requests_per_minute' => 30,
                    'tokens_per_minute' => 6000
                ],
                'supported_features' => ['chat'],
                'description' => 'Plataforma otimizada para velocidade, usando hardware proprietário LPU.',
                'is_active' => true,
                'priority' => 4
            ],
            [
                'name' => 'xAI Grok',
                'slug' => 'xai',
                'models' => [
                    'grok-beta' => 'Grok Beta',
                    'grok-vision-beta' => 'Grok Vision Beta',
                    'grok-2-1212' => 'Grok-2 1212',
                    'grok-2-vision-1212' => 'Grok-2 Vision 1212'
                ],
                'status' => 'active',
                'api_base_url' => 'https://api.x.ai/v1',
                'api_key_required' => true,
                'rate_limits' => [
                    'requests_per_minute' => 60,
                    'tokens_per_minute' => 30000
                ],
                'supported_features' => ['chat', 'image'],
                'description' => 'IA criada por xAI (Elon Musk), focada em ser útil e verdadeiramente inteligente.',
                'is_active' => true,
                'priority' => 5
            ],
            [
                'name' => 'Meta Llama',
                'slug' => 'meta',
                'models' => [
                    'llama3.1-405b-instruct' => 'Llama 3.1 405B Instruct',
                    'llama3.1-70b-instruct' => 'Llama 3.1 70B Instruct',
                    'llama3.1-8b-instruct' => 'Llama 3.1 8B Instruct',
                    'llama3-70b-instruct' => 'Llama 3 70B Instruct',
                    'llama3-8b-instruct' => 'Llama 3 8B Instruct',
                    'llama2-70b-chat' => 'Llama 2 70B Chat',
                    'llama2-13b-chat' => 'Llama 2 13B Chat',
                    'llama2-7b-chat' => 'Llama 2 7B Chat'
                ],
                'status' => 'active',
                'api_base_url' => 'https://api.llama-api.com',
                'api_key_required' => true,
                'rate_limits' => [
                    'requests_per_hour' => 1000
                ],
                'supported_features' => ['chat'],
                'description' => 'Modelos open-source da Meta, disponíveis através de provedores terceiros.',
                'is_active' => true,
                'priority' => 6
            ],
            [
                'name' => 'Mistral AI',
                'slug' => 'mistral',
                'models' => [
                    'mistral-large-latest' => 'Mistral Large',
                    'mistral-medium' => 'Mistral Medium',
                    'mistral-small' => 'Mistral Small',
                    'open-mistral-7b' => 'Open Mistral 7B',
                    'open-mixtral-8x7b' => 'Open Mixtral 8x7B',
                    'open-mixtral-8x22b' => 'Open Mixtral 8x22B',
                    'mistral-7b-instruct' => 'Mistral 7B Instruct',
                    'mixtral-8x7b-instruct' => 'Mixtral 8x7B Instruct'
                ],
                'status' => 'active',
                'api_base_url' => 'https://api.mistral.ai/v1',
                'api_key_required' => true,
                'rate_limits' => [
                    'requests_per_minute' => 60
                ],
                'supported_features' => ['chat'],
                'description' => 'IA europeia focada em modelos eficientes e open-source.',
                'is_active' => true,
                'priority' => 7
            ],
            [
                'name' => 'Cohere',
                'slug' => 'cohere',
                'models' => [
                    'command-r-plus' => 'Command R+',
                    'command-r' => 'Command R',
                    'command' => 'Command',
                    'command-light' => 'Command Light',
                    'base' => 'Base',
                    'base-light' => 'Base Light',
                    'embed-english-v3.0' => 'Embed English v3.0',
                    'embed-multilingual-v3.0' => 'Embed Multilingual v3.0'
                ],
                'status' => 'active',
                'api_base_url' => 'https://api.cohere.ai/v1',
                'api_key_required' => true,
                'rate_limits' => [
                    'requests_per_minute' => 100
                ],
                'supported_features' => ['chat', 'embeddings'],
                'description' => 'Especializada em geração de texto e embeddings semânticos.',
                'is_active' => true,
                'priority' => 8
            ],
            [
                'name' => 'Hugging Face',
                'slug' => 'huggingface',
                'models' => [
                    'microsoft/DialoGPT-medium' => 'DialoGPT Medium',
                    'microsoft/DialoGPT-large' => 'DialoGPT Large',
                    'facebook/blenderbot-400M-distill' => 'BlenderBot',
                    'google/flan-t5-base' => 'FLAN-T5 Base',
                    'google/flan-t5-large' => 'FLAN-T5 Large',
                    'distilbert-base-uncased' => 'DistilBERT Base',
                    'bert-base-uncased' => 'BERT Base'
                ],
                'status' => 'active',
                'api_base_url' => 'https://api-inference.huggingface.co',
                'api_key_required' => true,
                'rate_limits' => [
                    'requests_per_hour' => 10000
                ],
                'supported_features' => ['chat', 'embeddings', 'text-generation'],
                'description' => 'Plataforma open-source com milhares de modelos de IA hospedados.',
                'is_active' => true,
                'priority' => 9
            ],
            [
                'name' => 'Replicate',
                'slug' => 'replicate',
                'models' => [
                    'llama2-70b-chat' => 'Llama 2 70B Chat',
                    'stable-diffusion' => 'Stable Diffusion',
                    'codellama-34b-instruct' => 'Code Llama 34B',
                    'dolly-v2-12b' => 'Dolly v2 12B',
                    'vicuna-13b' => 'Vicuna 13B',
                    'llama-2-7b-chat' => 'Llama 2 7B Chat'
                ],
                'status' => 'active',
                'api_base_url' => 'https://api.replicate.com/v1',
                'api_key_required' => true,
                'rate_limits' => [
                    'requests_per_hour' => 1000
                ],
                'supported_features' => ['chat', 'image', 'code'],
                'description' => 'Plataforma para executar modelos de IA open-source na nuvem.',
                'is_active' => true,
                'priority' => 10
            ]
        ];
    }
}
