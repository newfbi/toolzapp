# Integração com Z-API

## Visão Geral

A integração com Z-API permite que o sistema BetBot processe mensagens do WhatsApp através da API oficial do Z-API, substituindo a integração anterior com Node.js.

## Configuração

### 1. Token Z-API

Adicione o token do Z-API nas configurações do sistema:

```sql
UPDATE settings SET zapi_token = 'SEU_TOKEN_ZAPI' WHERE id = 1;
```

### 2. Webhook

Configure o webhook no painel do Z-API para apontar para:
```
https://seudominio.com/webhook/z-api
```

## Estrutura dos Arquivos

### Services

- **ZApiService**: Service principal com toda a lógica de comunicação com Z-API, incluindo:
  - Cache de configurações
  - Connection pooling
  - Retry automático com backoff exponencial
  - Circuit breaker
  - Métricas de performance
  - Envio em lote otimizado

### Controllers

- **ZApiController**: Controller principal que recebe webhooks do Z-API
- **ZApiSenderController**: Controller auxiliar para envio de mensagens via Z-API

### Rotas

- **POST /webhook/z-api**: Endpoint para receber webhooks do Z-API
- **POST /z-api/send-text**: Envio de mensagem de texto
- **POST /z-api/send-image**: Envio de mensagem com imagem
- **POST /z-api/send-audio**: Envio de mensagem com áudio
- **POST /z-api/send-document**: Envio de mensagem com documento
- **POST /z-api/send-template**: Envio de template
- **POST /z-api/send-bulk**: Envio em lote
- **GET /z-api/performance**: Métricas de performance
- **GET /z-api/usage-stats**: Estatísticas de uso

## Estrutura do Webhook

O Z-API envia webhooks no seguinte formato:

### Mensagem Recebida

```json
{
  "type": "received_message",
  "data": {
    "phone": "5511999999999",
    "message": "Texto da mensagem",
    "messageType": "text",
    "instanceId": "ID_DA_INSTANCIA",
    "messageId": "ID_DA_MENSAGEM"
  }
}
```

### Status da Mensagem

```json
{
  "type": "message_status",
  "data": {
    "messageId": "ID_DA_MENSAGEM",
    "status": "delivered|read|failed",
    "instanceId": "ID_DA_INSTANCIA"
  }
}
```

## Tipos de Mensagem Suportados

- **text**: Mensagens de texto
- **image**: Imagens
- **audio**: Áudios (transcrição ainda não implementada)
- **document**: Documentos

## Funcionalidades

### Processamento de Mensagens

1. Recebe webhook do Z-API
2. Valida dados da mensagem
3. Busca agente pela instância
4. Verifica limites e configurações
5. Processa com OpenAI
6. Envia resposta via Z-API

### Envio de Mensagens

O ZApiService oferece métodos otimizados para:

- `sendTextMessage()`: Envio de texto com retry automático
- `sendImageMessage()`: Envio de imagens
- `sendAudioMessage()`: Envio de áudios
- `sendDocumentMessage()`: Envio de documentos
- `sendTemplateMessage()`: Envio de templates
- `sendBulkMessages()`: Envio em lote com connection pooling
- `markAsRead()`: Marcar como lida
- `checkInstanceStatus()`: Verificar status da instância
- `getPerformanceMetrics()`: Obter métricas de performance
- `getUsageStats()`: Obter estatísticas de uso

### Melhorias de Performance

1. **Cache de Configurações**: Configurações são cacheadas por 5 minutos
2. **Connection Pooling**: Reutilização de conexões HTTP
3. **Retry Automático**: Até 3 tentativas com backoff exponencial
4. **Envio em Lote**: Até 5 requisições simultâneas
5. **Métricas em Tempo Real**: Monitoramento de performance
6. **Circuit Breaker**: Proteção contra falhas em cascata

## Migração do Sistema Anterior

### Principais Mudanças

1. **Estrutura de Dados**: Adaptada para o formato JSON do Z-API
2. **Autenticação**: Usa token Z-API ao invés de autenticação WhatsApp Web
3. **Webhook**: Endpoint dedicado para receber notificações
4. **Envio**: Métodos específicos para cada tipo de mídia

### Compatibilidade

- Mantém compatibilidade com o sistema de agentes existente
- Preserva funcionalidades do OpenAI
- Mantém estrutura de banco de dados atual

## Configuração de Agentes

Os agentes devem ter o campo `phone_id` configurado com o ID da instância do Z-API correspondente.

## Logs

Todos os eventos são logados no Laravel Log:
- Mensagens recebidas
- Mensagens enviadas
- Erros de integração
- Status de instâncias

## Troubleshooting

### Problemas Comuns

1. **Token inválido**: Verifique se o token Z-API está correto
2. **Webhook não recebe**: Confirme se a URL está acessível
3. **Mensagens não enviadas**: Verifique logs para erros de API
4. **Agente não encontrado**: Confirme se o `phone_id` está configurado

### Logs Importantes

```bash
# Ver logs do Laravel
tail -f storage/logs/laravel.log

# Filtrar logs do Z-API
grep "Z-API" storage/logs/laravel.log
```

## Exemplo de Uso

### Via Service (Recomendado)

```php
use App\Services\ZApiService;

$zApiService = new ZApiService();

// Enviar mensagem de texto
$result = $zApiService->sendTextMessage('INSTANCE_ID', '5511999999999', 'Olá! Como posso ajudar?');

// Enviar imagem
$result = $zApiService->sendImageMessage('INSTANCE_ID', '5511999999999', 'https://exemplo.com/imagem.jpg', 'Legenda da imagem');

// Envio em lote
$messages = [
    ['phone' => '5511999999999', 'message' => 'Mensagem 1'],
    ['phone' => '5511888888888', 'message' => 'Mensagem 2']
];
$results = $zApiService->sendBulkMessages('INSTANCE_ID', $messages);

// Obter métricas
$metrics = $zApiService->getPerformanceMetrics();
```

### Via API Endpoints

```bash
# Enviar mensagem de texto
curl -X POST https://seudominio.com/z-api/send-text \
  -H "Content-Type: application/json" \
  -d '{
    "instance_id": "INSTANCE_ID",
    "phone": "5511999999999",
    "message": "Olá! Como posso ajudar?"
  }'

# Obter métricas de performance
curl https://seudominio.com/z-api/performance
```

## Próximos Passos

1. Implementar transcrição de áudio
2. Adicionar suporte a mais tipos de mídia
3. Implementar templates personalizados
4. Adicionar métricas de entrega
