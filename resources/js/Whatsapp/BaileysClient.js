import {
    default as makeWASocket,
    DisconnectReason,
    useMultiFileAuthState,
    Browsers
} from '@whiskeysockets/baileys';
import DatabaseAuth from './DatabaseAuth.js';
import { authBackupFolderFor, authFolderFor, ensureAuthRoot } from './AuthPaths.js';
import os from 'os';
import path from 'path';
import fs from 'fs';

const positiveIntegerFromEnv = (name, fallback, maximum = Number.MAX_SAFE_INTEGER) => {
    const value = Number.parseInt(process.env[name] || '', 10);
    if (!Number.isFinite(value) || value < 1) return fallback;
    return Math.min(value, maximum);
};

const wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

class BaileysClient {
    constructor(config) {
        this.config = config;
        this.userId = config.userId;
        this.agentId = config.agentId;
        ensureAuthRoot();
        this.authFolder = authFolderFor(this.agentId);
        this.databaseAuth = null;
        this.socket = null;
        this.isConnected = false;
        this.qrCode = null;
        this.phoneNumber = null;
        this.currentStatus = 'idle';
        this._lastCredsUpdate = null;
        this._connectionEstablished = false;
        this._processedMessageIds = new Set(); // Cache para deduplicação de mensagens
        this._isInitialSync = true; // Flag para sincronização inicial
        this._syncStartTime = null;
        this._heartbeatInterval = null; // Intervalo de heartbeat
        this._pingInterval = null; // Intervalo de ping
        this._reconnectAttempts = 0; // Contador de tentativas de reconexão
        this._maxReconnectAttempts = 5; // Máximo de tentativas antes de esperar mais
        // LOCKS E DEBOUNCING PARA PREVENIR RACE CONDITIONS
        this._reconnecting = false; // Lock para evitar reconexões simultâneas
        this._lastReconnectAttempt = 0; // Timestamp da última tentativa de reconexão
        this._reconnectDebounceMs = 8000; // 8 segundos mínimo entre tentativas
        this._sessionValidated = false; // Flag para marcar sessões confirmadas
        this._reconnectStartTime = null; // Timestamp do início das tentativas de reconexão
        this._sessionInvalidationCheck = null; // Revalidação única antes de descartar uma sessão após 401
        this._sessionRecheckSaw401 = false; // Segundo 401 confirma que a sessão não pode ser reaproveitada
        this._initializationPromise = null; // Serializa inicializações por instância
        this._reconnectTimer = null; // Evita múltiplos timers de reconexão para o mesmo socket

        // Todo envio passa por uma fila única por instância. Isso evita rajadas,
        // concorrência no mesmo socket e reenvios automáticos após resultado incerto.
        this._sendQueue = [];
        this._sendWorkerRunning = false;
        this._sendQueueMax = positiveIntegerFromEnv('WHATSAPP_SEND_QUEUE_MAX', 50, 500);
        this._sendIntervalMs = positiveIntegerFromEnv('WHATSAPP_SEND_INTERVAL_MS', 6000, 120000);
        this._maxMediaBytes = positiveIntegerFromEnv('WHATSAPP_MAX_MEDIA_BYTES', 25 * 1024 * 1024, 100 * 1024 * 1024);
        this._lastSendAt = 0;
        this._jidCache = new Map();
        this._jidCacheTtlMs = positiveIntegerFromEnv('WHATSAPP_JID_CACHE_TTL_MS', 300000, 3600000);
    }

    async initialize(isReconnect = false) {
        // Uma instância só pode possuir um socket ativo. Vários monitores podem
        // detectar a mesma queda quase ao mesmo tempo; nesse caso, todos devem
        // aguardar a mesma inicialização em vez de criar sockets concorrentes.
        if (this._initializationPromise) {
            console.warn(`⏳ [${this.agentId}] initialize() aguardando inicialização já em andamento`);
            return this._initializationPromise;
        }

        const initialization = this._initializeInternal(isReconnect);
        this._initializationPromise = initialization;

        try {
            return await initialization;
        } finally {
            if (this._initializationPromise === initialization) {
                this._initializationPromise = null;
            }
        }
    }

    async _initializeInternal(isReconnect = false) {
        // 🆕 LOCK DE RECONEXÃO: Prevenir reconexões simultâneas
        if (this._reconnecting) {
            console.warn(`⛔ [${this.agentId}] initialize() bloqueado — reconexão já em andamento`);
            return;
        }

        // 🆕 DEBOUNCING: Prevenir tentativas muito frequentes
        const now = Date.now();
        const timeSinceLastAttempt = now - this._lastReconnectAttempt;
        if (isReconnect && timeSinceLastAttempt < this._reconnectDebounceMs) {
            const waitTime = Math.ceil((this._reconnectDebounceMs - timeSinceLastAttempt) / 1000);
            console.warn(`⛔ [${this.agentId}] initialize() bloqueado — aguarde ${waitTime}s antes de tentar novamente`);
            return;
        }

        // 🔒 BLOQUEIO DEFINITIVO POR STATUS
        // Não permitir reconexão durante QR scan (exceto se for erro 515 pós-scan)
        if (!isReconnect && (this.currentStatus === 'qr' || this.currentStatus === 'connecting' || this.currentStatus === 'initializing')) {
            console.warn(`⛔ [${this.agentId}] initialize() bloqueado — status=${this.currentStatus} (não é reconexão)`);
            return;
        }

        // 🔒 BLOQUEIO ADICIONAL: Não permitir reconexão se já está inicializando
        if (isReconnect && this.currentStatus === 'initializing') {
            console.warn(`⛔ [${this.agentId}] initialize() bloqueado — já está inicializando`);
            return;
        }

        // 🆕 Não permitir reconexão durante QR ativo (prevenir invalidação durante scan)
        if (isReconnect && this.currentStatus === 'qr' && !this._lastCredsUpdate) {
            console.warn(`⛔ [${this.agentId}] initialize() bloqueado — QR ativo, aguardando scan`);
            return;
        }

        // 🆕 Marcar como reconectando e atualizar timestamp
        if (isReconnect) {
            this._reconnecting = true;
            this._lastReconnectAttempt = now;
        }

        try {
            // Encerrar completamente o socket anterior antes de abrir outro.
            // Apenas remover listeners não basta: o socket antigo ainda pode
            // continuar autenticado e provocar connectionReplaced/401.
            if (this.socket) {
                const previousSocket = this.socket;
                this.socket = null;
                this._clearConnectionIntervals();
                this.isConnected = false;
                this._connectionEstablished = false;

                try {
                    previousSocket.ev?.removeAllListeners();
                    previousSocket.end?.();
                } catch (closeError) {
                    console.warn(`⚠️ [${this.agentId}] Falha ao encerrar socket anterior:`, closeError.message);
                }
            }

            // Configurar DatabaseAuth
            if (!this.databaseAuth) {
                this.databaseAuth = new DatabaseAuth({
                    userId: this.userId,
                    agentId: this.agentId,
                    connection: this.config.connection
                });

                // Verificar se instância existe no banco
                const instanceExists = await this.databaseAuth.ensureInstanceExists();
                if (!instanceExists) {
                    throw new Error(`Instância não encontrada no banco para agente ${this.agentId}`);
                }
            }

            // Sistema de autenticação
            const { state, saveCreds, isNewSession } = await this.createDatabaseAuthState();
            this._isNewSession = isNewSession;

            // Configuração do logger - SEMPRE SILENT para evitar logs verbosos do Baileys
            const logger = {
                info: () => { },
                warn: () => { },
                error: () => { },
                debug: () => { },
                trace: () => { },
                fatal: () => { },
                child: (obj) => logger,
                level: 'silent'
            };

            // Configuração do socket com melhor tratamento de rede e conexão persistente
            const socketConfig = {
                auth: state,
                logger: logger,
                browser: Browsers.windows('Chrome'), // ✅ Windows/Chrome
                connectTimeoutMs: 90000, // 🛡️ Aumentado para 90s (melhor para conexões instáveis)
                keepAliveIntervalMs: 30000,
                qrTimeout: 120000, // 🛡️ Aumentado para 120s (2 minutos) - QR codes do WhatsApp expiram rápido
                defaultQueryTimeoutMs: 60000, // 🛡️ Aumentado para 60s (mais tolerante)
                markOnlineOnConnect: false,
                printQRInTerminal: false,
                syncFullHistory: false,
                fireInitQueries: true,
                // ✅ Suprimir logs verbosos do Baileys
                getMessage: async () => undefined, // Evita logs de mensagens antigas
                shouldIgnoreJid: () => false,
                patchMessageBeforeSending: (msg) => msg,
                // 🆕 Configurações adicionais para manter conexão ativa
                retryRequestDelayMs: 250, // Delay entre retries de requisições
                maxMsgRetryCount: 5, // Máximo de tentativas para enviar mensagens
            };

            this.socket = makeWASocket(socketConfig);

            // Handlers de eventos
            this.socket.ev.on('creds.update', async (creds) => {
                this._lastCredsUpdate = Date.now();

                try {
                    // ✅ Garantir que o diretório Auth existe antes de salvar
                    const authFolder = this.authFolder;
                    if (!fs.existsSync(authFolder)) {
                        fs.mkdirSync(authFolder, { recursive: true, mode: 0o700 });
                        console.log(`📁 [${this.agentId}] Diretório Auth criado: ${authFolder}`);
                    }
                    try {
                        fs.chmodSync(authFolder, 0o700);
                    } catch {
                        // A sessão continua utilizável quando o sistema de arquivos
                        // não permite chmod; o diretório raiz já é protegido.
                    }

                    await saveCreds(creds);
                } catch (error) {
                    console.error(`❌ [${this.agentId}] Erro ao salvar credenciais:`, error.message);
                    throw error; // Re-throw para tratamento upstream
                }

                // ✅ REMOVIDO: Não setar _connectionEstablished aqui
                // O estabelecimento de conexão deve ser feito apenas quando connection === 'open'
                // Isso evita problemas onde credenciais são atualizadas mas conexão ainda não está pronta
                // O handleConnectionEstablished() será chamado quando connection === 'open'
            });

            console.log(`🔧 [${this.agentId}] Configurando handlers de eventos...`);
            this.setupEventHandlers();
            console.log(`✅ [${this.agentId}] Handlers de eventos configurados`);

            // Status inicial
            // ✅ Se for reconexão e já tem sessão, não definir como 'initializing'
            // Deixar o Baileys gerenciar o status automaticamente
            if (!isReconnect || this._isNewSession) {
                await this.databaseAuth.updateStatus('initializing');
                this.currentStatus = 'initializing';
            } else {
                //console.log(`🔄 [${this.agentId}] Reconexão com sessão existente - aguardando status do Baileys`);
            }

            return this.socket;

        } catch (error) {
            console.error(`Erro ao inicializar BaileysClient para ${this.agentId}:`, error);
            throw error;
        } finally {
            // 🆕 SEMPRE liberar lock de reconexão
            if (isReconnect) {
                this._reconnecting = false;
            }
        }
    }

    setupEventHandlers() {
        console.log(`🔧 [${this.agentId}] Registrando handlers de eventos do Baileys...`);

        // Cada socket possui seus próprios listeners. Em uma reconexão, o
        // socket anterior pode emitir um evento atrasado; ele nunca deve
        // alterar o estado do socket novo.
        const eventSocket = this.socket;

        // Handler de erro global
        this.socket.ev.on('error', (error) => {
            // ✅ Filtrar erros conhecidos e não-críticos
            const errorMessage = error?.message || String(error);

            if (errorMessage.includes('MessageCounterError') ||
                errorMessage.includes('Key used already') ||
                errorMessage.includes('decrypt message') ||
                errorMessage.includes('Session error')) {
                // Erro de descriptografia - comum após restart, não é crítico
                console.warn(`⚠️ [${this.agentId}] Erro de sessão (ignorado):`, errorMessage);
                return;
            }

            // Outros erros são críticos
            console.error(`🚨 [${this.agentId}] Erro global do socket:`, error);
        });

        // Handler adicional para auth state changes
        this.socket.ev.on('auth-state.update', (update) => {
            console.warn(`🔐 [${this.agentId}] Auth state update:`, update);
        });

        // Handler para eventos importantes (debug)
        const originalEmit = this.socket.ev.emit;
        this.socket.ev.emit = function (event, ...args) {
            if (['messages.upsert', 'connection.update', 'creds.update', 'ready'].includes(event)) {
                //console.log(`📡 [${this.socket?.agentId || 'unknown'}] Evento emitido: ${event}`, args[0]);
            }
            return originalEmit.apply(this, [event, ...args]);
        }.bind({ socket: this.socket });

        // Handler para quando o socket está pronto
        this.socket.ev.on('ready', () => {
            console.log(`✅ [${this.agentId}] Evento 'ready' disparado pelo Baileys`);
            // ✅ O evento 'ready' indica que o socket está pronto, mas connection === 'open' é mais confiável
            // Não chamar handleConnectionEstablished aqui para evitar duplicação
            // O handleConnectionEstablished será chamado quando connection === 'open'
        });

        // Confirmações de mensagens enviadas pelo próprio número. O evento é
        // separado do messages.upsert e precisa ser persistido para distinguir
        // aceite pelo servidor, entrega e leitura pelo destinatário.
        this.socket.ev.on('messages.update', async (updates) => {
            if (!this.config.onMessageStatusUpdate || !Array.isArray(updates) || updates.length === 0) {
                return;
            }

            try {
                await this.config.onMessageStatusUpdate(updates, this.agentId);
            } catch (error) {
                console.error(`❌ [${this.agentId}] Erro ao processar confirmação de mensagem:`, error.message);
            }
        });

        // Em grupos, algumas confirmações chegam como recibos por participante.
        // O disparo atual é individual, mas encaminhamos o evento para manter o
        // listener completo sem interferir no fluxo de mensagens recebidas.
        this.socket.ev.on('message-receipt.update', async (updates) => {
            if (!this.config.onMessageReceiptUpdate || !Array.isArray(updates) || updates.length === 0) {
                return;
            }

            try {
                await this.config.onMessageReceiptUpdate(updates, this.agentId);
            } catch (error) {
                console.error(`❌ [${this.agentId}] Erro ao processar recibo de mensagem:`, error.message);
            }
        });

        // 🆕 SISTEMA DE MONITORAMENTO E HEARTBEAT BASEADO NO BAILEYS (independente do WebSocket)
        // 🆕 MELHORADO: Verificação mais frequente para manter conexão ativa
        this._heartbeatInterval = setInterval(() => {
            const wsReadyState = this.socket?.ws?.readyState;
            const isBaileysConnected = this.isConnected && this._connectionEstablished && wsReadyState === 1;

            // 🆕 HEARTBEAT DO BAILEYS - atualizar status periodicamente
            if (this.config.onBaileysHeartbeat) {
                this.config.onBaileysHeartbeat(this.agentId, isBaileysConnected, {
                    phoneNumber: this.phoneNumber,
                    instanceLid: this.instanceLid,
                    wsReadyState: wsReadyState,
                    currentStatus: this.currentStatus
                });
            }

            // 🆕 PING PROATIVO: Se conectado, verificar saúde da conexão periodicamente
            if (isBaileysConnected && wsReadyState === 1) {
                // Tentar acessar store para garantir que conexão está realmente ativa
                try {
                    if (this.socket?.store?.chats) {
                        this._lastActivity = Date.now();
                        // Acesso bem-sucedido = conexão saudável
                    }
                } catch (error) {
                    // Store inacessível pode indicar problema
                    console.warn(`⚠️ [${this.agentId}] Store pode estar inacessível durante heartbeat`);
                }
            }

            // 🔍 DETECÇÃO DE DESCONEXÃO SILENCIOSA CRÍTICA (baseada no WebSocket do Baileys, não Socket.IO)
            if (wsReadyState === 3 && this.isConnected) { // CLOSED
                console.warn(`🚨 [${this.agentId}] DESCONEXÃO SILENCIOSA DO BAILEYS DETECTADA`);

                this.isConnected = false;
                this._connectionEstablished = false;
                this.currentStatus = 'disconnected';

                // 🆕 ATUALIZAR STATUS NO BANCO BASEADO NO BAILEYS
                if (this.config.onBaileysStatusChange) {
                    this.config.onBaileysStatusChange(this.agentId, 'disconnected', {
                        errorMessage: 'WebSocket do Baileys desconectado silenciosamente'
                    });
                }

                if (this.config.onDisconnected) {
                    // 🆕 Desconexão silenciosa - não invalidar instância, apenas reconectar
                    this.config.onDisconnected(this.agentId, 'WebSocket do Baileys desconectado silenciosamente', true, false);
                }

                // 🆕 P0: CRÍTICO - Verificar lock antes de tentar reconectar
                if (!this._reconnecting) {
                    this._attemptReconnect(0);
                }
            }

            // 🔍 VERIFICAÇÃO DE CONEXÃO INSTÁVEL (CONNECTING ou CLOSING)
            if (wsReadyState === 2 && this.currentStatus === 'connected') { // CLOSING
                console.warn(`⚠️ [${this.agentId}] WebSocket está fechando - preparando reconexão`);
                // Não desconectar imediatamente, mas preparar para reconexão
            }

            // 🔍 VERIFICAÇÃO DE ATIVIDADE APENAS SE HOUVER PROBLEMAS
            if (this.currentStatus === 'connected' && wsReadyState !== 1) {
                const timeSinceLastActivity = Date.now() - (this._lastActivity || 0);
                if (timeSinceLastActivity > 30000) { // 🆕 REDUZIDO para 30s (detecção mais rápida)
                    try {
                        if (this.socket?.store?.chats) {
                            this._lastActivity = Date.now();
                        }
                    } catch (error) {
                        console.error(`❌ [${this.agentId}] Store inacessível - reconectando`);
                        this.isConnected = false;
                        this._connectionEstablished = false;
                        this.currentStatus = 'disconnected';

                        // 🆕 ATUALIZAR STATUS NO BANCO BASEADO NO BAILEYS
                        if (this.config.onBaileysStatusChange) {
                            this.config.onBaileysStatusChange(this.agentId, 'disconnected', {
                                errorMessage: 'Store inacessível'
                            });
                        }

                        if (this.config.onDisconnected) {
                            // 🆕 Store inacessível - não invalidar instância, apenas reconectar
                            this.config.onDisconnected(this.agentId, 'Store inacessível', true, false);
                        }

                        // 🆕 P0: CRÍTICO - Verificar lock antes de tentar reconectar
                        if (!this._reconnecting) {
                            this._attemptReconnect(0);
                        }
                    }
                }
            }
        }, 30000); // 🆕 AUMENTADO para 30 segundos (reduzir overhead)

        // 🆕 PING PERIÓDICO: Manter WebSocket ativo enviando requisições leves
        this._pingInterval = setInterval(async () => {
            if (this.isConnected && this._connectionEstablished && this.socket?.ws?.readyState === 1) {
                try {
                    // Tentar uma operação leve para manter conexão ativa
                    // Verificar se socket ainda está responsivo
                    if (this.socket?.user?.id) {
                        // Conexão está ativa
                        this._lastActivity = Date.now();
                    }
                } catch (error) {
                    // Erro silencioso - não é crítico
                }
            }
        }, 45000); // 🆕 AUMENTADO para 45 segundos (reduzir overhead)

        // Handler de mensagens com filtros para evitar processamento de histórico
        // 🆕 MELHORADO: Verificação adicional de sessão válida antes de processar
        console.log(`📨 [${this.agentId}] Registrando handler 'messages.upsert'`);
        this.socket.ev.on('messages.upsert', async (m) => {
            console.log(`📨 [${this.agentId}] Evento 'messages.upsert' recebido - tipo: ${m.type}, mensagens: ${m.messages?.length || 0}`);
            try {
                // 🆕 VERIFICAÇÃO ADICIONAL: Verificar se WebSocket ainda está ativo
                const wsReadyState = this.socket?.ws?.readyState;
                if (wsReadyState === 3) { // CLOSED
                    console.log(`🚫 [${this.agentId}] WebSocket fechado - ignorando mensagem`);
                    return;
                }

                // ✅ VERIFICAR SE A SESSÃO ESTÁ CONECTADA E ESTABELECIDA ANTES DE PROCESSAR
                // O Baileys gerencia o WebSocket internamente, então confiamos apenas nas flags de conexão
                if (!this.isConnected || !this._connectionEstablished) {
                    console.log(`🚫 [${this.agentId}] Ignorando mensagem - sessão não conectada (isConnected: ${this.isConnected}, _connectionEstablished: ${this._connectionEstablished})`);
                    return;
                }

                // 🆕 VERIFICAÇÃO ADICIONAL: Verificar se temos credenciais válidas
                const hasValidCreds = !!(this.socket?.authState?.state?.creds?.me || this.phoneNumber || this.isConnected);
                if (!hasValidCreds) {
                    console.log(`🚫 [${this.agentId}] Ignorando mensagem - credenciais não inicializadas`);
                    return;
                }

                // ✅ Verificar se phoneNumber está definido (conexão realmente estabelecida)
                if (!this.phoneNumber) {
                    console.log(`🚫 [${this.agentId}] Ignorando mensagem - phoneNumber não definido (conexão ainda não completa)`);
                    return;
                }

                // 🚫 FILTROS PARA EVITAR PROCESSAMENTO DE HISTÓRICO E LOOPS

                // 1. Só processar mensagens do tipo 'notify' (novas mensagens)
                // Outros tipos: 'append' (histórico), 'update' (atualizações), etc.
                if (m.type !== 'notify') {
                    //console.log(`📋 [${this.agentId}] Ignorando mensagem do tipo '${m.type}' (não é nova)`);
                    return;
                }

                // 2. Verificar se estamos em sincronização inicial
                if (this._isInitialSync) {
                    // Marcar início da sincronização se ainda não foi marcado
                    if (this._syncStartTime === null) {
                        this._syncStartTime = Date.now();
                        //console.log(`🔄 [${this.agentId}] Iniciando sincronização de histórico...`);
                    }

                    // Durante os primeiros 30 segundos, ser mais rigoroso com filtros
                    const syncDuration = Date.now() - this._syncStartTime;
                    if (syncDuration < 30000) { // 30 segundos
                        // Ignorar mensagens com timestamp muito antigo (mais de 1 hora)
                        if (m.messages && m.messages.length > 0) {
                            const oldestMessage = m.messages[0];
                            const messageTime = oldestMessage.messageTimestamp * 1000; // converter para ms
                            const ageInHours = (Date.now() - messageTime) / (1000 * 60 * 60);

                            if (ageInHours > 1) {
                                //console.log(`📅 [${this.agentId}] Ignorando mensagem antiga (${ageInHours.toFixed(1)}h) durante sync inicial`);
                                return;
                            }
                        }
                    }

                    // Após 30 segundos, considerar sincronização completa
                    if (syncDuration > 30000) {
                        this._isInitialSync = false;
                        //console.log(`✅ [${this.agentId}] Sincronização inicial completa`);
                    }
                }

                // 3. Deduplicação baseada em messageId
                if (m.messages && m.messages.length > 0) {
                    const newMessages = [];

                    for (const msg of m.messages) {
                        const messageId = msg.key.id;

                        // Verificar se já processamos esta mensagem
                        if (this._processedMessageIds.has(messageId)) {
                            //console.log(`🔄 [${this.agentId}] Mensagem duplicada ignorada: ${messageId}`);
                            continue;
                        }

                        // Adicionar ao cache de processadas (manter últimas 1000 mensagens)
                        this._processedMessageIds.add(messageId);
                        if (this._processedMessageIds.size > 1000) {
                            // Remover mensagens antigas do cache (FIFO)
                            const firstMessageId = this._processedMessageIds.values().next().value;
                            this._processedMessageIds.delete(firstMessageId);
                        }

                        newMessages.push(msg);
                    }

                    // Se não há mensagens novas após filtros, retornar
                    if (newMessages.length === 0) {
                        return;
                    }

                    // Substituir mensagens filtradas
                    m.messages = newMessages;
                }

                // 4. Verificação adicional de timestamp para mensagens muito antigas
                if (m.messages && m.messages.length > 0) {
                    const now = Date.now();
                    const filteredMessages = [];

                    for (const msg of m.messages) {
                        const messageTime = (msg.messageTimestamp || 0) * 1000; // timestamp em segundos
                        const ageInMinutes = (now - messageTime) / (1000 * 60);

                        // Ignorar mensagens com mais de 5 minutos (pode ser histórico atrasado)
                        if (ageInMinutes > 5) {
                            //console.log(`📅 [${this.agentId}] Ignorando mensagem antiga (${ageInMinutes.toFixed(1)}min): ${msg.key.id}`);
                            continue;
                        }

                        // Verificar se timestamp é razoável (não no futuro)
                        if (messageTime > now + (1000 * 60)) { // 1 minuto no futuro
                            console.warn(`⚠️ [${this.agentId}] Mensagem com timestamp no futuro ignorada: ${msg.key.id}`);
                            continue;
                        }

                        filteredMessages.push(msg);
                    }

                    if (filteredMessages.length === 0) {
                        return;
                    }

                    m.messages = filteredMessages;
                }

                // Agora processar mensagens filtradas
                if (this.config.onMessage && m.messages && m.messages.length > 0) {
                    console.log(`📨 [${this.agentId}] Processando ${m.messages.length} mensagem(ns) recebida(s)`);
                    // ✅ CORREÇÃO: Passar o objeto m completo apenas UMA vez (não em loop)
                    // O handleIncomingMessageBaileys já itera sobre as mensagens
                    await this.config.onMessage(m, this.agentId);
                } else {
                    if (!this.config.onMessage) {
                        console.warn(`⚠️ [${this.agentId}] Callback onMessage não definido!`);
                    } else {
                        console.log(`📨 [${this.agentId}] Nenhuma mensagem válida para processar após filtros`);
                    }
                }
            } catch (error) {
                // ✅ Capturar erros de descriptografia sem quebrar o servidor
                const errorMessage = error.message || String(error);
                if (errorMessage.includes('MessageCounterError') ||
                    errorMessage.includes('Key used already') ||
                    errorMessage.includes('decrypt message') ||
                    errorMessage.includes('closed session') ||
                    errorMessage.includes('Decrypted message with closed session') ||
                    errorMessage.includes('session closed') ||
                    errorMessage.includes('session is closed')) {
                    // 🆕 MELHORADO: Erros comuns quando mensagens chegam após desconexão
                    // Estes erros são esperados após desconexões e não devem quebrar o fluxo
                    // Suprimir completamente para evitar poluição de logs
                    return;
                } else {
                    // 🆕 FILTRAR OUTROS ERROS DE DESCRIPTOGRAFIA COMUNS
                    // Alguns erros de Baileys são normais e não críticos
                    const nonCriticalErrors = [
                        'decrypt failed',
                        'could not decrypt',
                        'invalid message',
                        'corrupt message',
                        'invalid padding',
                        'bad mac',
                        'invalid key usage'
                    ];

                    const isNonCritical = nonCriticalErrors.some(nonCrit =>
                        errorMessage.toLowerCase().includes(nonCrit.toLowerCase())
                    );

                    if (isNonCritical) {
                        // Logar como warning mas não como erro
                        console.warn(`⚠️ [${this.agentId}] Erro não-crítico de descriptografia:`, errorMessage);
                        return;
                    }

                    // Apenas erros realmente críticos chegam aqui
                    console.error(`❌ [${this.agentId}] Erro crítico ao processar mensagem:`, error);
                }
            }
        });

        // Handler de conexão
        this.socket.ev.on('connection.update', async (update) => {
            if (this.socket !== eventSocket) {
                console.warn(`⏭️ [${this.agentId}] Evento de socket antigo ignorado`);
                return;
            }

            const { connection, lastDisconnect, qr, isNewLogin } = update;

            if (qr) {
                // ✅ Validar QR antes de processar
                if (!qr || typeof qr !== 'string' || qr.trim() === '') {
                    console.error(`❌ [${this.agentId}] QR code inválido recebido do Baileys (tipo: ${typeof qr}, valor: ${qr})`);
                    return;
                }

                // ✅ Validar comprimento mínimo do QR
                if (qr.length < 10) {
                    console.error(`❌ [${this.agentId}] QR code muito curto (${qr.length} caracteres)`);
                    return;
                }

                // 🆕 DEBUG: Mostrar início do QR para validar formato
                console.log(`🎯 [${this.agentId}] QR Code gerado (${qr.length} caracteres)`);
                console.log(`🔍 [${this.agentId}] QR início: "${qr.substring(0, 50)}..."`);

                this.qrCode = qr;
                this.currentStatus = 'qr';

                // ✅ SIMPLIFICADO: Não salvar QR no banco aqui
                // O server.js vai converter para data URL e salvar apenas a string base64
                // Isso evita problemas de serialização/deserialização

                if (this.config.onQr) {
                    try {
                        this.config.onQr(qr, this.agentId);
                    } catch (qrError) {
                        console.error(`❌ [${this.agentId}] Erro no callback onQr:`, qrError.message);
                    }
                } else {
                    console.warn(`⚠️ [${this.agentId}] Callback onQr não configurado`);
                }
            }

            if (connection === 'connecting') {
                // ✅ Só atualizar para 'connecting' se não for reconexão rápida
                // Em reconexões com sessão, o Baileys pode ir direto para 'open'
                if (this.currentStatus !== 'qr' && this.currentStatus !== 'connected') {
                    await this.databaseAuth.updateStatus('connecting');
                    this.currentStatus = 'connecting';

                    // 🆕 ATUALIZAR STATUS NO BANCO BASEADO NO BAILEYS
                    if (this.config.onBaileysStatusChange) {
                        await this.config.onBaileysStatusChange(this.agentId, 'connecting', {});
                    }
                }
            }

            if (connection === 'close') {
                const error = lastDisconnect?.error;
                const statusCode = error?.output?.statusCode ?? error?.data?.statusCode ?? error?.statusCode;

                // Durante QR, tratar erro 515 de forma especial
                if (this.currentStatus === 'qr' && statusCode === 515) {
                    const hasRecentCredsUpdate = this._lastCredsUpdate &&
                        (Date.now() - this._lastCredsUpdate) < 30000;

                    if (hasRecentCredsUpdate) {
                        console.warn(`🔄 [${this.agentId}] Erro 515 após scan do QR — tentando reconectar`);
                        // Tentar reconectar após scan
                        setTimeout(async () => {
                            try {
                                await this.initialize(true);
                            } catch (err) {
                                console.error(`❌ [${this.agentId}] Falha na reconexão:`, err.message);
                            }
                        }, 3000);
                    } else {
                        console.warn(`⚠️ [${this.agentId}] Erro 515 durante geração de QR — aguardando`);
                    }
                    return;
                }

                // 🆕 IGNORAR DESCONEXÕES FALSAS: Se não há statusCode e conexão está estabelecida
                // Isso previne loops de reconexão causados por eventos falsos do Baileys
                if (!statusCode && this._connectionEstablished && this.isConnected) {
                    console.log(`⏭️ [${this.agentId}] Evento de desconexão sem statusCode ignorado (conexão ativa)`);
                    return;
                }

                // Para outros erros ou quando não está em QR
                this.isConnected = false;
                // O socket atual deixou de ser uma sessão estabelecida. Esta
                // flag precisa ser zerada para que o próximo evento "open"
                // execute novamente toda a sincronização com o banco.
                this._connectionEstablished = false;
                this.currentStatus = 'disconnected';
                let shouldReconnect = true;
                let disconnectReason = 'Desconhecido';
                let shouldInvalidateInstance = false; // 🆕 Flag para invalidar instância apenas quando realmente necessário

                // 🆕 ORGANIZAÇÃO: Separar desconexão do socket da invalidação da instância
                // O socket pode desconectar temporariamente sem invalidar a sessão do Baileys

                if (statusCode === 401 || statusCode === DisconnectReason.loggedOut) {
                    // 401 normalmente significa logout, mas uma primeira
                    // ocorrência isolada não deve apagar a sessão imediatamente.
                    // O reconciliador reabre o socket uma única vez usando as
                    // credenciais existentes e só descarta após um segundo 401.
                    if (this._sessionInvalidationCheck) {
                        this._sessionRecheckSaw401 = true;
                        console.warn(`⚠️ [${this.agentId}] Segundo 401 recebido durante a confirmação da sessão`);
                        return;
                    }

                    const confirmation = this._confirmSessionAfter401();
                    this._sessionInvalidationCheck = confirmation;

                    try {
                        const result = await confirmation;

                        if (result.recovered) {
                            console.log(`✅ [${this.agentId}] Sessão recuperada durante a confirmação do 401`);
                            return;
                        }

                        shouldReconnect = false;
                        disconnectReason = result.confirmed
                            ? 'Sessão encerrada pelo WhatsApp (401/logout confirmado)'
                            : result.needsQr
                                ? 'Sessão 401 detectada; novo pareamento por QR pode ser necessário'
                                : 'Sessão 401 detectada; confirmação inconclusiva, credenciais preservadas';
                        shouldInvalidateInstance = result.confirmed;

                        if (result.confirmed) {
                            console.warn(`🚫 [${this.agentId}] 401 confirmado após revalidação - removendo credenciais`);
                            this._removeAuthCredentials();
                        } else {
                            console.warn(`🛡️ [${this.agentId}] Sessão preservada após 401 não confirmado`);
                        }

                        if (this.config.onBaileysStatusChange) {
                            await this.config.onBaileysStatusChange(this.agentId, 'disconnected', {
                                errorMessage: disconnectReason,
                                requiresNewQr: result.confirmed || result.needsQr,
                            });
                        }

                        if (this.config.onDisconnected) {
                            await this.config.onDisconnected(
                                this.agentId,
                                disconnectReason,
                                false,
                                shouldInvalidateInstance
                            );
                        }
                    } finally {
                        this._sessionInvalidationCheck = null;
                    }

                    return;
                } else if (statusCode === DisconnectReason.connectionReplaced ||
                    statusCode === DisconnectReason.forbidden ||
                    statusCode === DisconnectReason.multideviceMismatch) {
                    // Conflito de sessão, conta proibida ou incompatibilidade de
                    // dispositivo não deve ser martelado por reconexões automáticas.
                    disconnectReason = `Sessão não recuperável (${statusCode})`;
                    shouldReconnect = false;
                    shouldInvalidateInstance = true;
                } else if (statusCode === DisconnectReason.badSession) {
                    // Credenciais inválidas: aguardar intervenção explícita para
                    // gerar QR, evitando apagar/recriar sessão em loop.
                    disconnectReason = 'Credenciais de sessão inválidas';
                    shouldReconnect = false;
                    shouldInvalidateInstance = true;
                } else if (statusCode === 515) {
                    // 🆕 STREAM ERROR: Validar contexto antes de invalidar
                    if (this._connectionEstablished) {
                        disconnectReason = 'STREAM_ERRORED';

                        // Verificar se é erro recorrente ou isolado
                        const recentConnection = this._lastCredsUpdate &&
                            (Date.now() - this._lastCredsUpdate) < 60000; // 🆕 AUMENTADO para 60 segundos

                        if (recentConnection) {
                            // Erro logo após conexão - pode ser temporário (comum após QR scan)
                            console.warn(`⚠️ [${this.agentId}] Erro 515 logo após conexão - tentando reconectar (comum após QR scan)`);
                            shouldReconnect = true;
                            shouldInvalidateInstance = false; // 🆕 NÃO invalidar - é esperado após QR scan
                        } else {
                            // Erro após conexão estabelecida há mais tempo - pode ser corrompida
                            console.warn(`⚠️ [${this.agentId}] Erro 515 após conexão estabelecida - tentando reconectar`);
                            shouldReconnect = true;
                            shouldInvalidateInstance = false; // 🆕 Dar chance de reconectar antes de invalidar
                        }
                    } else {
                        // Não estava conectado - erro durante conexão inicial
                        disconnectReason = 'Conexão falhou';
                        shouldReconnect = true;
                        shouldInvalidateInstance = false;
                    }
                } else if (statusCode === 503) {
                    // Indisponibilidade de serviço: reconexão com backoff é segura;
                    // não remover credenciais nem criar nova sessão.
                    disconnectReason = 'Serviço do WhatsApp temporariamente indisponível';
                    shouldReconnect = true;
                    shouldInvalidateInstance = false;
                } else if (statusCode >= 500) {
                    // 🛡️ ERROS DE SERVIDOR WHATSAPP
                    disconnectReason = 'Servidor WhatsApp indisponível';
                    shouldReconnect = true; // Tentar novamente
                    shouldInvalidateInstance = false; // Não invalidar - erro do servidor

                    console.warn(`⚠️ [${this.agentId}] Erro de servidor WhatsApp (${statusCode}) - tentando reconectar`);
                } else if (!statusCode || statusCode === DisconnectReason.connectionLost || statusCode === DisconnectReason.connectionClosed) {
                    // 🛡️ PERDA DE CONEXÃO (network issues)
                    disconnectReason = 'Conexão perdida';
                    shouldReconnect = true;
                    shouldInvalidateInstance = false; // Não invalidar - problema de rede

                    //console.log(`📡 [${this.agentId}] Conexão perdida - reconexão automática em andamento`);
                } else {
                    // Códigos desconhecidos/4xx ficam parados para evitar repetição
                    // de uma sessão potencialmente bloqueada.
                    disconnectReason = `Desconexão não recuperável (${statusCode})`;
                    shouldReconnect = false;
                    shouldInvalidateInstance = true;
                }

                console.warn(`⚠️ WhatsApp desconectado ${this.agentId}: ${disconnectReason} (shouldReconnect: ${shouldReconnect}, shouldInvalidate: ${shouldInvalidateInstance})`);

                // 🆕 ATUALIZAR STATUS NO BANCO BASEADO NO BAILEYS (antes de chamar callbacks)
                if (this.config.onBaileysStatusChange) {
                    await this.config.onBaileysStatusChange(this.agentId, 'disconnected', {
                        errorMessage: disconnectReason,
                        requiresNewQr: shouldInvalidateInstance
                    });
                }

                // 🆕 Invalidar instância apenas se realmente necessário e não houver callback
                // Se houver callback, ele decidirá quando invalidar após tentativas de reconexão
                if (this.currentStatus !== 'qr' && this.config.onDisconnected) {
                    // Passar flag de invalidação para o callback decidir
                    this.config.onDisconnected(this.agentId, disconnectReason, shouldReconnect, shouldInvalidateInstance);
                } else if (shouldInvalidateInstance && this._connectionEstablished) {
                    // Se não chamar callback mas precisa invalidar, fazer aqui
                    console.warn(`🗑️ [${this.agentId}] Invalidando instância diretamente (sem callback)`);
                    await this._deleteInstanceCompletely();
                }
            }

            if (connection === 'open') {
                console.log(`✅ [${this.agentId}] Conexão aberta - estabelecendo conexão completa`);
                await this.handleConnectionEstablished();
            }
        });

    }

    async handleConnectionEstablished() {
        // 🆕 SEMPRE atualizar isConnected, mesmo se _connectionEstablished já for true
        // Isso garante que após Health Monitor recovery, mensagens não sejam rejeitadas
        this.isConnected = true;

        if (this._connectionEstablished && this.currentStatus === 'connected') {
            console.log(`⚠️ [${this.agentId}] Conexão já estabelecida, apenas atualizando isConnected flag`);
            return;
        }

        console.log(`🔌 [${this.agentId}] Estabelecendo conexão completa...`);
        this._connectionEstablished = true;

        // Extração do telefone e @lid da própria instância
        const user = this.socket.user;
        const userId = user?.id || 'unknown';
        this.phoneNumber = userId.split('@')[0]?.split(':')[0] || 'unknown';

        // Extração do @lid da própria instância
        let instanceLid = null;
        if (user?.lid) {
            // Se tem lid direto, usar ele
            instanceLid = user.lid.replace('@lid', '').replace('@s.whatsapp.net', '');
        } else if (userId.includes('@')) {
            // Fallback para o user.id sem @
            instanceLid = userId.split('@')[0]?.split(':')[0];
        }

        this.instanceLid = instanceLid; // Armazenar @lid da própria instância
        console.log(`📱 [${this.agentId}] Instância conectada - Phone: ${this.phoneNumber}, LID: ${this.instanceLid}`);

        // Resetar flags de sincronização quando conexão é estabelecida
        this._isInitialSync = true;
        this._syncStartTime = Date.now();

        // 🆕 MARCAR SESSÃO COMO VALIDADA (conexão bem-sucedida)
        this._sessionValidated = true;

        // 🆕 RESETAR CONTADOR DE RECONEXÕES (conexão bem-sucedida)
        this._reconnectAttempts = 0;

        // Atualizar banco de dados com phone_number, instance_lid e limpar qr_code
        await this.databaseAuth.updateStatus('connected', {
            phoneNumber: this.phoneNumber,
            instanceLid: this.instanceLid, // Adicionar @lid da instância
            qrCode: null // Limpar QR code após conexão bem-sucedida
        });
        this.currentStatus = 'connected';

        // 🆕 ATUALIZAR STATUS NO BANCO BASEADO NO BAILEYS
        if (this.config.onBaileysStatusChange) {
            await this.config.onBaileysStatusChange(this.agentId, 'connected', {
                phoneNumber: this.phoneNumber,
                instanceLid: this.instanceLid,
                skipConnectedAt: false
            });
        }

        // Disparar callback com informações extras
        if (this.config.onConnected) {
            this.config.onConnected(this.agentId, this.phoneNumber, this.instanceLid);
        }
    }

    _notConnectedError(message = 'Cliente não está conectado') {
        const error = new Error(message);
        error.code = 'WHATSAPP_NOT_CONNECTED';
        error.deliveryUncertain = false;
        return error;
    }

    _sendAbortedError() {
        const error = new Error('Envio cancelado porque a conexão foi encerrada antes do envio.');
        error.code = 'WHATSAPP_SEND_ABORTED';
        error.deliveryUncertain = false;
        return error;
    }

    _rejectQueuedSends(error = this._sendAbortedError()) {
        while (this._sendQueue.length > 0) {
            const item = this._sendQueue.shift();
            item.reject(error);
        }
    }

    async _drainSendQueue() {
        if (this._sendWorkerRunning) return;

        this._sendWorkerRunning = true;
        try {
            while (this._sendQueue.length > 0) {
                if (!this.isConnected || !this._connectionEstablished || !this.socket) {
                    this._rejectQueuedSends(this._notConnectedError());
                    break;
                }

                const item = this._sendQueue.shift();
                const elapsed = Date.now() - this._lastSendAt;
                const remaining = Math.max(0, this._sendIntervalMs - elapsed);

                if (remaining > 0) {
                    await wait(remaining);
                }

                if (!this.isConnected || !this._connectionEstablished || !this.socket) {
                    item.reject(this._notConnectedError());
                    this._rejectQueuedSends(this._notConnectedError());
                    break;
                }

                try {
                    const result = await item.operation();
                    this._lastSendAt = Date.now();
                    item.resolve(result);
                } catch (error) {
                    this._lastSendAt = Date.now();

                    // Um erro depois de chamar socket.sendMessage não informa se o
                    // servidor recebeu a mensagem. Nunca repetir automaticamente.
                    if (error && error.deliveryUncertain === true) {
                        this._rejectQueuedSends(error);
                    }

                    item.reject(error);
                    if (error?.deliveryUncertain === true) break;
                }
            }
        } finally {
            this._sendWorkerRunning = false;
        }
    }

    _enqueueSend(operation) {
        if (!this.isConnected || !this._connectionEstablished || !this.socket) {
            return Promise.reject(this._notConnectedError());
        }

        if (this._sendQueue.length >= this._sendQueueMax) {
            const error = new Error('Fila de envio cheia; aguarde o processamento dos envios pendentes.');
            error.code = 'WHATSAPP_SEND_QUEUE_FULL';
            error.deliveryUncertain = false;
            return Promise.reject(error);
        }

        const result = new Promise((resolve, reject) => {
            this._sendQueue.push({ operation, resolve, reject });
        });

        void this._drainSendQueue();
        return result;
    }

    _messagePayload(content) {
        if (typeof content === 'string') {
            if (content.length > 65536) {
                throw new Error('Mensagem excede o limite de 65.536 caracteres.');
            }
            return { text: content };
        }

        if (!content || typeof content !== 'object' || Array.isArray(content)) {
            throw new Error('Conteúdo de mensagem inválido.');
        }

        if (typeof content.text === 'string' && content.text.length > 65536) {
            throw new Error('Mensagem excede o limite de 65.536 caracteres.');
        }

        for (const mediaField of ['image', 'video', 'audio', 'document', 'sticker']) {
            const media = content[mediaField];
            if (media && (Buffer.isBuffer(media) || media instanceof Uint8Array) && media.byteLength > this._maxMediaBytes) {
                throw new Error(`Mídia excede o limite de ${Math.round(this._maxMediaBytes / 1024 / 1024)} MB.`);
            }
        }

        return content;
    }

    async _sendPayload(to, content) {
        const targetJid = await this.resolveTargetJid(to);
        const payload = this._messagePayload(content);

        try {
            const result = await this.socket.sendMessage(targetJid, payload);
            console.log(`✅ [BaileysClient ${this.agentId}] Envio concluído (${targetJid})`);
            return result;
        } catch (error) {
            error.deliveryUncertain = true;
            error.code = error.code || 'WHATSAPP_SEND_FAILED';
            console.error(`❌ [BaileysClient ${this.agentId}] Falha no envio (${targetJid}):`, error.message);
            throw error;
        }
    }

    /**
     * Resolve e valida o JID de destino no WhatsApp garantindo DDI 55 para números brasileiros.
     */
    async resolveTargetJid(to) {
        if (!to || typeof to !== 'string') return to;

        // Se for @lid ou grupo, não alterar
        if (to.includes('@lid') || to.includes('@g.us')) {
            return to;
        }

        const cleanDigits = to.replace('@s.whatsapp.net', '').replace('@c.us', '').replace(/[^\d]/g, '');
        if (!/^\d{8,15}$/.test(cleanDigits)) {
            throw new Error('Número de WhatsApp inválido.');
        }

        let formattedNumber = cleanDigits;
        if (cleanDigits.length === 10 || cleanDigits.length === 11) {
            formattedNumber = `55${cleanDigits}`;
        }

        const cached = this._jidCache.get(formattedNumber);
        if (cached && cached.expiresAt > Date.now()) {
            return cached.jid;
        }

        if (this.socket && typeof this.socket.onWhatsApp === 'function') {
            try {
                const results = await this.socket.onWhatsApp(formattedNumber);
                if (results?.[0]?.exists && results[0].jid) {
                    this._jidCache.set(formattedNumber, {
                        jid: results[0].jid,
                        expiresAt: Date.now() + this._jidCacheTtlMs
                    });
                    return results[0].jid;
                }
            } catch (err) {
                console.warn(`⚠️ [${this.agentId}] onWhatsApp falhou: ${err.message}`);
            }
        }

        const fallbackJid = `${formattedNumber}@s.whatsapp.net`;
        this._jidCache.set(formattedNumber, {
            jid: fallbackJid,
            expiresAt: Date.now() + this._jidCacheTtlMs
        });
        return fallbackJid;
    }

    async sendMessage(to, content) {
        return this._enqueueSend(() => this._sendPayload(to, content));
    }

    /**
     * 🆕 Envia imagem via WhatsApp
     * @param {string} to - JID do destinatário
     * @param {string|Buffer} imagePath - Caminho do arquivo ou Buffer da imagem
     * @param {string} caption - Legenda opcional
     * @returns {Promise<Object>} Resultado do envio
     */
    async sendImage(to, imagePath, caption = '') {
        return this._enqueueSend(async () => {
            const imageBuffer = typeof imagePath === 'string' ? fs.readFileSync(imagePath) : imagePath;
            return this._sendPayload(to, {
                image: imageBuffer,
                caption: caption
            });
        });
    }

    /**
     * 🆕 Envia vídeo via WhatsApp
     * @param {string} to - JID do destinatário
     * @param {string|Buffer} videoPath - Caminho do arquivo ou Buffer do vídeo
     * @param {string} caption - Legenda opcional
     * @returns {Promise<Object>} Resultado do envio
     */
    async sendVideo(to, videoPath, caption = '') {
        return this._enqueueSend(async () => {
            const videoBuffer = typeof videoPath === 'string' ? fs.readFileSync(videoPath) : videoPath;
            return this._sendPayload(to, {
                video: videoBuffer,
                caption: caption
            });
        });
    }

    /**
     * 🆕 Envia áudio via WhatsApp
     * @param {string} to - JID do destinatário
     * @param {string|Buffer} audioPath - Caminho do arquivo ou Buffer do áudio
     * @param {boolean} ptt - Push-to-talk (áudio de voz) - padrão true
     * @returns {Promise<Object>} Resultado do envio
     */
    async sendAudio(to, audioPath, ptt = true) {
        return this._enqueueSend(async () => {
            const audioBuffer = typeof audioPath === 'string' ? fs.readFileSync(audioPath) : audioPath;
            return this._sendPayload(to, {
                audio: audioBuffer,
                ptt: ptt,
                mimetype: 'audio/ogg; codecs=opus'
            });
        });
    }

    /**
     * 🆕 Envia documento via WhatsApp
     * @param {string} to - JID do destinatário
     * @param {string|Buffer} documentPath - Caminho do arquivo ou Buffer do documento
     * @param {string} filename - Nome do arquivo
     * @param {string} mimetype - Tipo MIME do arquivo
     * @returns {Promise<Object>} Resultado do envio
     */
    async sendDocument(to, documentPath, filename, mimetype = 'application/pdf') {
        return this._enqueueSend(async () => {
            const documentBuffer = typeof documentPath === 'string' ? fs.readFileSync(documentPath) : documentPath;
            return this._sendPayload(to, {
                document: documentBuffer,
                fileName: filename,
                mimetype: mimetype
            });
        });
    }

    async disconnect() {
        this._rejectQueuedSends(this._notConnectedError('Cliente desconectado antes do envio.'));
        this._cancelScheduledReconnect();

        // 🆕 P1: Limpar intervalos de monitoramento (sempre executar)
        this._clearConnectionIntervals();

        // 🆕 P1: Desconectar socket (com tratamento de erro)
        try {
            if (this.socket) {
                this.socket.ev.removeAllListeners();
                this.socket.end();
                this.socket = null;
            }
        } catch (error) {
            console.warn(`⚠️ [${this.agentId}] Erro ao desconectar socket:`, error.message);
        }

        // Atualizar flags locais (sempre executar)
        this.isConnected = false;
        this._connectionEstablished = false;

        // 🆕 P1: Limpar sessão global (com tratamento de erro)
        try {
            if (typeof global !== 'undefined' && global.activeSessions) {
                global.activeSessions.delete(this.agentId);
            }
        } catch (error) {
            console.warn(`⚠️ [${this.agentId}] Erro ao limpar sessão global:`, error.message);
        }

        // 🆕 P1: Atualizar banco (com tratamento de erro)
        try {
            await this.databaseAuth.updateStatus('disconnected');
            this.currentStatus = 'disconnected';
        } catch (error) {
            console.warn(`⚠️ [${this.agentId}] Erro ao atualizar status no banco:`, error.message);
            // Atualizar status local mesmo se banco falhar
            this.currentStatus = 'disconnected';
        }
    }

    async logout() {
        try {
            this._rejectQueuedSends(this._notConnectedError('Logout solicitado antes do envio.'));
            this._cancelScheduledReconnect();

            // 🆕 P0: CRÍTICO - Limpar intervalos PRIMEIRO para evitar memory leak
            this._clearConnectionIntervals();

            // Deletar arquivos de autenticação
            const authFolder = this.authFolder;
            if (fs.existsSync(authFolder)) {
                fs.rmSync(authFolder, { recursive: true, force: true });
            }

            // Resetar credenciais em memória
            if (this.socket?.authState?.state?.creds) {
                this.socket.authState.state.creds.registered = false;
                this.socket.authState.state.creds.me = undefined;
                this.socket.authState.state.creds.account = undefined;
            }

            // Desconectar socket. Os listeners são removidos antes do logout
            // para que o próprio evento 401 do logout manual não dispare o
            // reconciliador automático.
            const socket = this.socket;
            this.socket = null;
            if (socket) {
                try {
                    socket.ev?.removeAllListeners();
                    if (this.isConnected && typeof socket.logout === 'function') {
                        await socket.logout();
                    }
                } catch (logoutErr) {
                    console.warn(`Logout via socket falhou:`, logoutErr.message);
                }

                socket.end(DisconnectReason.loggedOut);
                await new Promise(resolve => setTimeout(resolve, 2000));
            }

            // Limpar estado
            this.isConnected = false;
            this._connectionEstablished = false;
            this.qrCode = null;
            this.phoneNumber = null;

            // Limpar caches globais
            if (global?.activeSessions) global.activeSessions.delete(this.agentId);
            if (global?.keysCache) global.keysCache.delete(this.agentId);

            await this.databaseAuth.updateStatus('disconnected');
            this.currentStatus = 'disconnected';

        } catch (error) {
            console.error(`❌ [${this.agentId}] Erro ao fazer logout:`, error);
            throw error;
        }
    }

    /**
     * 🆕 FORÇA REFRESH DO QR CODE
     * Desconecta sessão atual e reinicializa imediatamente para gerar novo QR
     * Útil quando usuário quer novo QR sem esperar timeout de 120s
     */
    async forceRefreshQR() {
        try {
            console.log(`🔄 [${this.agentId}] Forçando refresh de QR Code...`);

            // 1. Desconectar sessão atual (se existir)
            if (this.socket) {
                try {
                    console.log(`🔌 [${this.agentId}] Desconectando sessão atual...`);
                    await this.disconnect();
                } catch (disconnectError) {
                    console.warn(`⚠️ [${this.agentId}] Erro ao desconectar:`, disconnectError.message);
                }
            }

            // 2. Limpar credenciais (forçar novo QR)
            const authFolder = this.authFolder;
            if (fs.existsSync(authFolder)) {
                try {
                    console.log(`🗑️ [${this.agentId}] Limpando credenciais antigas...`);
                    fs.rmSync(authFolder, { recursive: true, force: true });
                } catch (fsError) {
                    console.warn(`⚠️ [${this.agentId}] Erro ao limpar credenciais:`, fsError.message);
                }
            }

            // 3. Resetar flags internas
            this.isConnected = false;
            this._connectionEstablished = false;
            this._sessionValidated = false;
            this.qrCode = null;
            this.phoneNumber = null;
            this.currentStatus = 'disconnected';

            // 4. Reinicializar imediatamente (gera novo QR)
            console.log(`🚀 [${this.agentId}] Reinicializando para gerar novo QR...`);
            await this.initialize(false); // false = não é reconexão, é nova sessão

            // ✅ initialize() pode retornar sem criar socket se foi bloqueado
            // pelos guards (ex.: reconexão automática em curso) — reportar honestamente
            if (!this.socket) {
                console.warn(`⛔ [${this.agentId}] Refresh de QR não executou - inicialização bloqueada`);
                return {
                    success: false,
                    error: 'Inicialização bloqueada (reconexão em andamento). Tente novamente em instantes.',
                    message: 'Inicialização bloqueada (reconexão em andamento). Tente novamente em instantes.'
                };
            }

            console.log(`✅ [${this.agentId}] Refresh de QR Code concluído`);
            return { success: true, message: 'QR Code atualizado com sucesso' };

        } catch (error) {
            console.error(`❌ [${this.agentId}] Erro ao forçar refresh de QR:`, error);
            throw error;
        }
    }

    async createDatabaseAuthState() {
        const authFolder = this.authFolder;

        // Criar pasta se não existir
        if (!fs.existsSync(authFolder)) {
            fs.mkdirSync(authFolder, { recursive: true, mode: 0o700 });
        }
        try {
            fs.chmodSync(authFolder, 0o700);
        } catch (error) {
            console.warn(`⚠️ [${this.agentId}] Não foi possível restringir permissões da sessão:`, error.message);
        }

        // Usar useMultiFileAuthState
        const { state, saveCreds } = await useMultiFileAuthState(authFolder);

        const hasCreds = state.creds && Object.keys(state.creds).length > 0;
        const isRegistered = hasCreds && state.creds.registered === true;

        // Verificar se há problemas de desserialização
        if (hasCreds) {
            try {
                const credsFile = path.join(authFolder, 'creds.json');
                if (fs.existsSync(credsFile)) {
                    const rawCreds = JSON.parse(fs.readFileSync(credsFile, 'utf8'));
                    const hasIssue = (rawCreds.me && !state.creds.me) ||
                        (rawCreds.account && !state.creds.account);

                    if (hasIssue) {
                        throw new Error('Credenciais inconsistentes no disco.');
                    }
                }
            } catch (error) {
                // Não apagar a sessão automaticamente: uma falha de leitura pode
                // ser transitória e a exclusão forçaria novo pareamento sem necessidade.
                throw new Error(`Credenciais inválidas ou corrompidas: ${error.message}`);
            }
        }

        return {
            state,
            saveCreds,
            isNewSession: !hasCreds
        };
    }

    async cleanupSession() {
        // 🆕 Limpar intervalos de monitoramento
        if (this._heartbeatInterval) {
            clearInterval(this._heartbeatInterval);
            this._heartbeatInterval = null;
        }
        if (this._pingInterval) {
            clearInterval(this._pingInterval);
            this._pingInterval = null;
        }

        if (this.socket) {
            this.socket.ev.removeAllListeners();
        }
        this.isConnected = false;
        this.qrCode = null;
        this._connectionEstablished = false;

        // ✅ Limpar cache de mensagens processadas
        this._processedMessageIds.clear();
        this._isInitialSync = true;
        this._syncStartTime = null;
        this._reconnectAttempts = 0; // Reset contador de reconexão

        if (typeof global !== 'undefined' && global.activeSessions) {
            global.activeSessions.delete(this.agentId);
        }
    }

    /**
     * ✅ RESTAURADO: este método estava inteiro dentro de um bloco de comentário
     * aberto (docblock mal fechado antes dele), então não existia em runtime e as
     * chamadas this._attemptReconnect(0) nos heartbeats lançavam TypeError silencioso,
     * matando a recuperação de desconexões silenciosas do WebSocket.
     *
     * 🆕 Método de reconexão com backoff exponencial
     * Tenta reconectar automaticamente com delays crescentes
     */
    async _attemptReconnect(attempt = 0) {
        // 🆕 Verificar lock de reconexão
        if (this._reconnecting) {
            console.warn(`⛔ [${this.agentId}] Reconexão já em andamento, ignorando tentativa`);
            return;
        }

        // 🆕 P1: Verificar timeout máximo (5 minutos desde primeira tentativa)
        if (!this._reconnectStartTime) {
            this._reconnectStartTime = Date.now();
        } else {
            const elapsedTime = Date.now() - this._reconnectStartTime;
            const MAX_RECONNECT_TIME = 5 * 60 * 1000; // 5 minutos

            if (elapsedTime > MAX_RECONNECT_TIME) {
                console.warn(`🛑 [${this.agentId}] Timeout de reconexão atingido (5 minutos) - parando tentativas`);
                this._reconnectAttempts = 0;
                this._reconnectStartTime = null;

                // 🆕 P1: Notificar que requer intervenção manual
                if (this.config.onDisconnected) {
                    this.config.onDisconnected(this.agentId, 'Timeout de reconexão - requer intervenção manual', false, true);
                }
                return;
            }
        }

        // 🆕 Verificar número máximo de tentativas
        if (attempt >= this._maxReconnectAttempts) {
            console.warn(`🛑 [${this.agentId}] Máximo de tentativas de reconexão atingido (${this._maxReconnectAttempts})`);
            this._reconnectAttempts = 0; // Resetar contador
            this._reconnectStartTime = null; // Resetar timer
            return;
        }

        // 🆕 Backoff exponencial: 3s, 6s, 12s, 24s, 48s
        const delays = [3000, 6000, 12000, 24000, 48000];
        const delay = delays[Math.min(attempt, delays.length - 1)];

        console.log(`🔄 [${this.agentId}] Tentativa de reconexão ${attempt + 1}/${this._maxReconnectAttempts} em ${delay / 1000}s...`);

        if (this._reconnectTimer) {
            console.log(`⏭️ [${this.agentId}] Reconexão já agendada; mantendo apenas um timer`);
            return;
        }

        this._reconnectTimer = setTimeout(async () => {
            this._reconnectTimer = null;
            try {
                // Verificar se ainda precisa reconectar
                if (this.isConnected && this._connectionEstablished) {
                    console.log(`✅ [${this.agentId}] Já reconectado, cancelando tentativa`);
                    this._reconnectAttempts = 0;
                    this._reconnectStartTime = null; // Resetar timer
                    return;
                }

                // Tentar reconectar
                await this.initialize(true); // isReconnect = true

                // Aguardar um pouco para verificar se conectou
                await new Promise(resolve => setTimeout(resolve, 3000));

                if (this.isConnected && this._connectionEstablished) {
                    console.log(`✅ [${this.agentId}] Reconexão bem-sucedida na tentativa ${attempt + 1}`);
                    this._reconnectAttempts = 0; // Resetar contador
                    this._reconnectStartTime = null; // Resetar timer
                } else {
                    // Tentar novamente
                    console.warn(`⚠️ [${this.agentId}] Reconexão falhou, tentando novamente...`);
                    this._reconnectAttempts = attempt + 1;
                    await this._attemptReconnect(attempt + 1);
                }
            } catch (error) {
                console.error(`❌ [${this.agentId}] Erro na tentativa de reconexão ${attempt + 1}:`, error.message);
                this._reconnectAttempts = attempt + 1;

                // Tentar novamente se não atingiu o máximo
                if (attempt + 1 < this._maxReconnectAttempts) {
                    await this._attemptReconnect(attempt + 1);
                } else {
                    console.warn(`🛑 [${this.agentId}] Todas as tentativas de reconexão falharam`);
                    this._reconnectAttempts = 0;
                    this._reconnectStartTime = null; // Resetar timer
                }
            }
        }, delay);
    }

    /**
     * Confirma um 401 antes de destruir a sessão persistida.
     *
     * Um único evento pode ocorrer durante uma troca/instabilidade de socket.
     * A revalidação usa as credenciais atuais, não envia mensagens e tem prazo
     * curto. Resultado inconclusivo nunca apaga credenciais automaticamente.
     */
    async _confirmSessionAfter401() {
        this._sessionRecheckSaw401 = false;

        await wait(2500);

        if (this.isConnected && this._connectionEstablished) {
            return { recovered: true, confirmed: false, needsQr: false };
        }

        try {
            await this.initialize(true);
        } catch (error) {
            console.warn(`⚠️ [${this.agentId}] Revalidação após 401 não iniciou:`, error.message);
            return { recovered: false, confirmed: false, needsQr: false };
        }

        const deadline = Date.now() + 15000;
        while (Date.now() < deadline) {
            if (this.isConnected && this._connectionEstablished) {
                return { recovered: true, confirmed: false, needsQr: false };
            }

            if (this._sessionRecheckSaw401) {
                return { recovered: false, confirmed: true, needsQr: false };
            }

            if (this.currentStatus === 'qr' || this.qrCode) {
                return { recovered: false, confirmed: false, needsQr: true };
            }

            await wait(500);
        }

        return { recovered: false, confirmed: false, needsQr: false };
    }

    _clearConnectionIntervals() {
        if (this._heartbeatInterval) {
            clearInterval(this._heartbeatInterval);
            this._heartbeatInterval = null;
        }
        if (this._pingInterval) {
            clearInterval(this._pingInterval);
            this._pingInterval = null;
        }
    }

    _cancelScheduledReconnect() {
        if (this._reconnectTimer) {
            clearTimeout(this._reconnectTimer);
            this._reconnectTimer = null;
        }
        this._reconnectAttempts = 0;
        this._reconnectStartTime = null;
    }

    /**
     * Preserva a sessão invalidada em quarentena em vez de apagá-la.
     * Um 401 confirmado não é recuperável pelo Baileys, mas manter uma cópia
     * protegida evita perda irreversível durante diagnóstico ou auditoria.
     * A nova sessão explícita remove a quarentena antes de gerar o QR.
     */
    _removeAuthCredentials() {
        try {
            if (!fs.existsSync(this.authFolder)) {
                return null;
            }

            ensureAuthRoot();
            const quarantineRoot = authBackupFolderFor(this.agentId);
            fs.mkdirSync(quarantineRoot, { recursive: true, mode: 0o700 });
            fs.chmodSync(quarantineRoot, 0o700);

            const quarantinePath = path.join(
                quarantineRoot,
                `session_${new Date().toISOString().replace(/[:.]/g, '-')}`
            );
            fs.renameSync(this.authFolder, quarantinePath);
            fs.chmodSync(quarantinePath, 0o700);
            console.warn(`🛡️ [${this.agentId}] Credenciais preservadas em quarentena após 401 confirmado: ${quarantinePath}`);
            return quarantinePath;
        } catch (error) {
            // Nunca apagar como fallback: se a quarentena falhar, preservar a
            // pasta original é mais seguro que destruir a única cópia.
            console.warn(`⚠️ [${this.agentId}] Não foi possível colocar credenciais em quarentena; sessão preservada:`, error.message);
            return null;
        }
    }

    /**
     * 🆕 Invalida completamente a instância (apenas quando realmente necessário)
     * Deve ser chamado apenas quando a sessão está realmente expirada/inválida
     * e não pode ser recuperada através de reconexão
     */
    async _deleteInstanceCompletely() {
        // 🛡️ Proteção: Não deletar durante QR ou conexão inicial
        if (!this._connectionEstablished) {
            console.warn(`🛡️ [${this.agentId}] Protegido: Não deletando durante QR/connecting`);
            return;
        }

        console.log(`🗑️ [${this.agentId}] Invalidando instância completamente`);

        // 🆕 P1: CRÍTICO - Limpar intervalos PRIMEIRO para evitar memory leak
        try {
            if (this._heartbeatInterval) {
                clearInterval(this._heartbeatInterval);
                this._heartbeatInterval = null;
            }
            if (this._pingInterval) {
                clearInterval(this._pingInterval);
                this._pingInterval = null;
            }
        } catch (error) {
            console.warn(`⚠️ [${this.agentId}] Erro ao limpar intervalos:`, error.message);
        }

        // Limpar sessão ativa
        if (global.activeSessions) {
            global.activeSessions.delete(this.agentId);
        }

        // Limpar estado interno
        this.isConnected = false;
        this._connectionEstablished = false;
        this.currentStatus = 'disconnected';

        // Atualizar banco de dados
        try {
            await this.databaseAuth.updateStatus('disconnected', {
                errorMessage: 'Sessão invalidada - requer novo QR Code'
            });
        } catch (error) {
            console.warn(`⚠️ [${this.agentId}] Erro ao atualizar status no banco:`, error.message);
        }

        // 🆕 NÃO deletar credenciais automaticamente aqui
        // Deixar que o sistema de auto-recovery ou usuário decida quando limpar
        // As credenciais podem ainda ser válidas mesmo com socket desconectado
    }

    getStatus() {
        return {
            isConnected: this.isConnected,
            phoneNumber: this.phoneNumber,
            qrCode: this.qrCode,
            agentId: this.agentId
        };
    }
}

export default BaileysClient;
