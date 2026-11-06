import dotenv from 'dotenv';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
dotenv.config({ path: path.resolve(__dirname, '../../../.env') });

'use strict';

/**
 * SERVIDOR PONTE WHATSAPP
 *
 * Este servidor NÃO gerencia sessões WhatsApp.
 * Ele apenas:
 * - Recebe solicitações do frontend (Laravel)
 * - Inicializa instâncias do BaileysClient
 * - Repassa QR codes e status
 * - Encaminha mensagens
 * - Gerencia desconexões solicitadas
 *
 * Toda lógica de autenticação, criptografia e sessão fica no BaileysClient.
 */

// ✅ FILTRO GLOBAL PARA SUPRIMIR LOGS VERBOSOS DO BAILEYS (aplicado antes de qualquer import)
const originalConsoleLog = console.log;
const originalConsoleWarn = console.warn;
const originalConsoleInfo = console.info;
const originalConsoleDebug = console.debug;
const originalConsoleError = console.error;

const shouldSuppressBaileysLog = (...args) => {
    // Verifica se algum argumento é um objeto SessionEntry ou contém propriedades específicas
    const hasSessionEntry = args.some(arg => {
        if (typeof arg === 'object' && arg !== null) {
            // Verifica propriedades características do SessionEntry
            return (
                arg.constructor?.name === 'SessionEntry' ||
                (arg._chains !== undefined && arg.registrationId !== undefined) ||
                (arg.indexInfo !== undefined && arg.currentRatchet !== undefined) ||
                (arg.pendingPreKey !== undefined && arg.indexInfo !== undefined) ||
                // Verificação adicional para objetos que podem ser SessionEntry serializados
                (arg._chains && typeof arg._chains === 'object' && arg.registrationId && typeof arg.registrationId === 'number')
            );
        }
        return false;
    });

    // Verifica se o primeiro argumento é uma string que menciona "Closing session"
    const firstArg = args[0];
    const isClosingSession = typeof firstArg === 'string' && firstArg.includes('Closing session');

    // Verifica strings que contêm informações de sessão
    const message = args.map(arg => {
        if (typeof arg === 'string') return arg;
        if (typeof arg === 'object' && arg !== null) {
            try {
                return JSON.stringify(arg);
            } catch {
                return String(arg);
            }
        }
        return String(arg);
    }).join(' ');

    // Filtrar logs específicos do Baileys relacionados a SessionEntry
    // 🆕 ADICIONADO: Filtrar mensagens de sessão fechada (comuns após desconexão)
    return (
        hasSessionEntry ||
        isClosingSession ||
        message.includes('Closing session:') ||
        message.includes('SessionEntry') ||
        message.includes('Closing session: SessionEntry') ||
        message.includes('indexInfo:') ||
        message.includes('pendingPreKey:') ||
        message.includes('baseKey:') ||
        message.includes('remoteIdentityKey:') ||
        message.includes('_chains:') ||
        message.includes('currentRatchet:') ||
        message.includes('ephemeralKeyPair:') ||
        message.includes('registrationId:') ||
        message.includes('rootKey:') ||
        message.includes('lastRemoteEphemeralKey:') ||
        // 🆕 MENSAGENS DE SESSÃO FECHADA (comuns após desconexão)
        message.includes('Decrypted message with closed session') ||
        message.includes('closed session') ||
        message.includes('session closed') ||
        message.includes('session is closed')
    );
};

console.log = (...args) => {
    if (shouldSuppressBaileysLog(...args)) {
        return; // Suprimir completamente
    }
    originalConsoleLog.apply(console, args);
};

console.warn = (...args) => {
    if (shouldSuppressBaileysLog(...args)) {
        return; // Suprimir completamente
    }
    originalConsoleWarn.apply(console, args);
};

console.info = (...args) => {
    if (shouldSuppressBaileysLog(...args)) {
        return; // Suprimir completamente
    }
    originalConsoleInfo.apply(console, args);
};

console.debug = (...args) => {
    if (shouldSuppressBaileysLog(...args)) {
        return; // Suprimir completamente
    }
    originalConsoleDebug.apply(console, args);
};

console.error = (...args) => {
    if (shouldSuppressBaileysLog(...args)) {
        return; // Suprimir completamente
    }
    originalConsoleError.apply(console, args);
};

// ✅ HANDLERS GLOBAIS DE ERRO PARA PREVENIR CRASH
process.on('uncaughtException', (error) => {
    console.error('🚨 UNCAUGHT EXCEPTION:', error);
    console.error('🚨 Stack trace:', error.stack);

    // 🆕 Se for erro de banco, tentar reconectar
    if (error.message.includes('connection') || error.message.includes('pool')) {
        console.log('🔄 Tentando reconectar pool de banco após erro crítico...');
        // O pool será reconectado automaticamente nas próximas queries
    }

// O processo deve sair para que o supervisor reinicie em estado limpo.
    setTimeout(() => process.exit(1), 100);
});

process.on('unhandledRejection', (reason, promise) => {
    console.error('🚨 UNHANDLED REJECTION:', reason);
    console.error('🚨 Promise:', promise);

    // 🆕 Se for erro de banco, tentar reconectar
    if (reason && typeof reason === 'object' && reason.message &&
        (reason.message.includes('connection') || reason.message.includes('pool'))) {
        console.log('🔄 Tentando reconectar pool de banco após rejeição não tratada...');
        // O pool será reconectado automaticamente nas próximas queries
    }

    // O processo deve sair para que o supervisor reinicie em estado limpo.
    setTimeout(() => process.exit(1), 100);
});

import { handleIncomingMessage } from './Listener.js';
import BaileysClient from './BaileysClient.js';
import cors from 'cors';
import qrcode from 'qrcode';
import express from 'express';
import { createServer as createHttpServer } from 'http';
import db from './ConnDb.js';
import fs from 'fs';
import { downloadMediaMessage } from '@whiskeysockets/baileys';
import { writeFile, mkdir } from 'fs/promises';
import crypto from 'node:crypto';
import { authBackupFolderFor, authFolderFor, ensureAuthRoot } from './AuthPaths.js';

/**
 * Move uma sessão que não pode ser validada para uma área de quarentena.
 * Falhas de leitura ou recuperação não devem destruir a única cópia das
 * credenciais; o pareamento explícito continua podendo limpar essa área.
 */
function quarantineAuthFolder(agentId, reason = 'invalid') {
    const authFolder = authFolderFor(agentId);
    if (!fs.existsSync(authFolder)) {
        return null;
    }

    ensureAuthRoot();
    const quarantineRoot = authBackupFolderFor(agentId);
    fs.mkdirSync(quarantineRoot, { recursive: true, mode: 0o700 });
    fs.chmodSync(quarantineRoot, 0o700);

    const safeReason = String(reason).replace(/[^A-Za-z0-9_-]/g, '_').slice(0, 40) || 'invalid';
    const destination = path.join(
        quarantineRoot,
        `session_${safeReason}_${new Date().toISOString().replace(/[:.]/g, '-')}`
    );

    fs.renameSync(authFolder, destination);
    fs.chmodSync(destination, 0o700);
    console.warn(`🛡️ [${agentId}] Credenciais movidas para quarentena: ${destination}`);
    return destination;
}

// Origens permitidas dinamicamente (suporta localhost, proxy reverso e domínio de produção)
const CLIENT_ORIGIN = process.env.ALLOWED_ORIGINS ?
    process.env.ALLOWED_ORIGINS.split(',').map(s => s.trim()) :
    (process.env.NODE_ENV === 'production'
        ? false
        : ['http://localhost:8000', 'http://127.0.0.1:8000']);

const app = express();

app.use(cors({
    origin: CLIENT_ORIGIN,
    methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    credentials: true,
    allowedHeaders: ['Content-Type', 'Authorization', 'X-Requested-With', 'x-dispatch-token'],
}));

// Rota de teste para verificar status
app.get('/status', (req, res) => {
    res.json({
        server: 'running',
        clients_count: clients.size,
        timestamp: new Date().toISOString()
    });
});

// 🆕 Rota para forçar refresh de QR Code
app.use(express.json()); // Middleware para parse de JSON
app.post('/refresh-qr', async (req, res) => {
    try {
        if (!authorizeDispatchRequest(req, res)) return;
        const { agent_id } = req.body;

        if (!agent_id) {
            return res.status(400).json({
                success: false,
                error: 'agent_id é obrigatório'
            });
        }

        console.log(`📡 [HTTP] Solicitação de refresh de QR para ${agent_id}`);

        // Chamar função de refresh
        const result = await refreshQRCode(agent_id, null);

        res.json(result);

    } catch (error) {
        console.error('❌ [HTTP] Erro ao processar refresh de QR:', error);
        res.status(500).json({
            success: false,
            error: error.message
        });
    }
});

// ========================================
// 📤 API HTTP PARA DISPAROS DO LARAVEL (fila anti-spam)
// O Laravel agenda e chama aqui item a item, respeitando limites.
// ========================================

/**
 * Token obrigatório para as rotas internas de disparo.
 */
function authorizeDispatchRequest(req, res) {
    const expected = process.env.WHATSAPP_HTTP_TOKEN || '';
    if (!expected) {
        res.status(503).json({ success: false, error: 'Autenticação interna não configurada' });
        return false;
    }
    const received = String(req.headers['x-dispatch-token'] || '');
    if (received.length === expected.length && crypto.timingSafeEqual(Buffer.from(received), Buffer.from(expected))) {
        return true;
    }
    res.status(401).json({ success: false, error: 'Token de disparo inválido' });
    return false;
}

// 📡 Status de uma instância específica (para o Laravel checar antes de enviar)
app.get('/instance-status/:agentId', async (req, res) => {
    try {
        if (!authorizeDispatchRequest(req, res)) return;

        const agentId = req.params.agentId;
        const client = clients.get(agentId);
        const wsReadyState = client?.socket?.ws?.readyState;
        const isConnected = !!(client?.isConnected && client._connectionEstablished && wsReadyState !== 2 && wsReadyState !== 3);

        res.json({
            success: true,
            agent_id: agentId,
            connected: isConnected,
            phone_number: client?.phoneNumber ?? null
        });
    } catch (error) {
        res.status(500).json({ success: false, error: error.message });
    }
});

// Troca segura de provedor: encerra apenas o cliente Baileys em memória.
// As credenciais locais permanecem no disco para permitir voltar ao modo local.
app.post('/disconnect-instance', async (req, res) => {
    try {
        if (!authorizeDispatchRequest(req, res)) return;

        const agentId = req.body?.agent_id || req.body?.agentId;
        if (!agentId) {
            return res.status(400).json({ success: false, error: 'agent_id é obrigatório' });
        }

        await cleanupClient(String(agentId), 'provider_switch');
        return res.json({ success: true, agent_id: String(agentId) });
    } catch (error) {
        console.error('❌ [HTTP] Erro ao desconectar instância para troca de provedor:', error.message);
        return res.status(500).json({ success: false, error: error.message });
    }
});

// 📨 Envia UMA mensagem (texto ou imagem com legenda) via instância conectada.
// Chamado pelo job da fila do Laravel, um item por vez, com limite de saída.
app.post('/send-direct', async (req, res) => {
    let rateReserved = false;
    try {
        if (!authorizeDispatchRequest(req, res)) return;

        const { agent_id, to, text = '', media_path = null, media_url = null } = req.body || {};

        if (!agent_id || !to) {
            return res.status(400).json({
                success: false,
                error: 'agent_id e to são obrigatórios'
            });
        }

        if (!await ensureBaileysProvider(agent_id)) {
            return res.status(409).json({
                success: false,
                error: 'A instância está configurada para outro provedor.',
                provider_conflict: true
            });
        }

        const client = clients.get(agent_id);
        if (!client || !client.isConnected || !client._connectionEstablished) {
            return res.status(409).json({
                success: false,
                error: 'Instância não está conectada',
                instance_disconnected: true
            });
        }

        const caption = typeof text === 'string' ? text : '';

        // Mesmo rate limiter do fluxo interativo (camada extra de proteção)
        const rateCheck = checkMessageRateLimit(agent_id, to);
        if (!rateCheck.allowed) {
            return res.status(429).json({
                success: false,
                error: rateCheck.reason || 'Rate limit excedido',
                rate_limited: true
            });
        }
        rateReserved = true;

        let result = null;

        if (media_path) {
            // Mídia local só pode ser lida dentro de storage/app; o endpoint é
            // interno, mas ainda assim não deve aceitar caminhos arbitrários.
            const mediaRoot = path.resolve(__dirname, '../../../storage/app');
            const requestedPath = path.isAbsolute(media_path)
                ? path.resolve(media_path)
                : path.resolve(mediaRoot, media_path);
            const isInsideMediaRoot = requestedPath === mediaRoot || requestedPath.startsWith(`${mediaRoot}${path.sep}`);

            if (!isInsideMediaRoot || !fs.existsSync(requestedPath)) {
                throw new Error('Caminho de mídia local inválido.');
            }

            result = await client.sendImage(to, requestedPath, caption);
        } else if (media_url) {
            // Imagem externa: baixa no momento do envio
            const mediaResponse = await fetch(media_url, { signal: AbortSignal.timeout(25000) });
            if (!mediaResponse.ok) {
                throw new Error(`Falha ao baixar mídia (${mediaResponse.status})`);
            }
            const declaredLength = Number(mediaResponse.headers.get('content-length') || 0);
            if (declaredLength > client._maxMediaBytes) {
                throw new Error('Mídia externa excede o limite permitido.');
            }
            const buffer = Buffer.from(await mediaResponse.arrayBuffer());
            if (buffer.byteLength > client._maxMediaBytes) {
                throw new Error('Mídia externa excede o limite permitido.');
            }
            result = await client.sendMessage(to, { image: buffer, caption });
        } else {
            if (!caption.trim()) {
                releaseMessageRateLimit(agent_id);
                rateReserved = false;
                return res.status(400).json({
                    success: false,
                    error: 'Texto obrigatório quando não há mídia'
                });
            }
            result = await client.sendMessage(to, caption);
        }

        recordMessageSent(agent_id, to);
        rateReserved = false;

        io.to(agent_id).emit('message', {
            agent_id: agent_id,
            from: client.phoneNumber,
            to: to,
            direction: 'output',
            source: 'dispatch', // marca origem da fila
            timestamp: Date.now()
        });

        res.json({
            success: true,
            message_id: result?.key?.id ?? null,
            delivery_status: result?.key?.id
                ? (consumeOutgoingMessageStatus(agent_id, result.key.id) || 'accepted')
                : 'unknown'
        });
    } catch (error) {
        if (rateReserved) {
            if (error?.deliveryUncertain === true) {
                recordMessageSent(agent_id, to);
            } else {
                releaseMessageRateLimit(agent_id);
            }
        }
        console.error('❌ [HTTP] Erro em send-direct:', error.message);
        // O Laravel registra a falha no item da fila. Se o socket falhou depois
        // de iniciar o envio, não é seguro reenviar automaticamente.
        res.json({
            success: false,
            error: error.message,
            delivery_uncertain: error?.deliveryUncertain === true
        });
    }
});


// Marcador para identificar respostas do bot e evitar loops
const BOT_RESPONSE_MARKER = '\u200B'; // Caractere invisível
const server = createHttpServer(app);
import { Server as SocketIOServer } from 'socket.io';
const io = new SocketIOServer(server, {
    cors: {
        origin: CLIENT_ORIGIN, // Permite o Laravel como cliente
        methods: ['GET', 'POST'],
        credentials: true
    },
    // Configurações para debug e compatibilidade
    transports: ['websocket', 'polling'],
    allowEIO3: true,
    pingTimeout: 60000,
    pingInterval: 25000
});
const clients = new Map();
const statuses = new Map();
// Lock por agentId para impedir que duas requisições de QR/restart criem
// objetos BaileysClient diferentes antes de clients.set() ser executado.
const sessionInitializationLocks = new Set();

// Estados de confirmação emitidos pelo Baileys para mensagens do próprio
// número. O cache cobre a pequena janela entre o retorno de sendMessage() e a
// persistência do ID no Laravel.
const outgoingMessageStatusCache = new Map();
const outgoingStatusRank = Object.freeze({
    unknown: 0,
    accepted: 1,
    pending: 1,
    server_ack: 2,
    delivered: 3,
    read: 4,
    played: 5,
    error: 0,
    deleted: 0,
});
const baileysStatusNames = Object.freeze({
    0: 'error',
    1: 'pending',
    2: 'server_ack',
    3: 'delivered',
    4: 'read',
    5: 'played',
    6: 'deleted',
});

function normalizeBaileysMessageStatus(update) {
    const numericStatus = Number(update?.update?.status);
    return Number.isInteger(numericStatus) ? baileysStatusNames[numericStatus] || null : null;
}

function cleanupOutgoingMessageStatusCache() {
    const cutoff = Date.now() - (10 * 60 * 1000);
    for (const [key, value] of outgoingMessageStatusCache.entries()) {
        if (value.updatedAt < cutoff) {
            outgoingMessageStatusCache.delete(key);
        }
    }
}

function cacheOutgoingMessageStatus(agentId, messageId, status) {
    cleanupOutgoingMessageStatusCache();
    const cacheKey = `${agentId}:${messageId}`;
    const previous = outgoingMessageStatusCache.get(cacheKey);
    if (!previous || (outgoingStatusRank[status] || 0) > (outgoingStatusRank[previous.status] || 0)) {
        outgoingMessageStatusCache.set(cacheKey, { status, updatedAt: Date.now() });
    }
}

function consumeOutgoingMessageStatus(agentId, messageId) {
    cleanupOutgoingMessageStatusCache();
    const cacheKey = `${agentId}:${messageId}`;
    const cached = outgoingMessageStatusCache.get(cacheKey);
    if (cached) outgoingMessageStatusCache.delete(cacheKey);
    return cached?.status || null;
}

async function persistOutgoingMessageStatus(agentId, messageId, status) {
    if (!agentId || !messageId || !status) return;

    const [rows] = await db.query(
        `SELECT i.id, i.delivery_status, i.delivered_at, i.read_at
         FROM whatsapp_dispatch_items i
         INNER JOIN whatsapp_dispatches d ON d.id = i.dispatch_id
         WHERE d.agent_id = ? AND i.whatsapp_message_id = ?
         LIMIT 1`,
        [agentId, messageId]
    );

    if (!rows?.[0]) {
        cacheOutgoingMessageStatus(agentId, messageId, status);
        return;
    }

    const item = rows[0];
    const currentRank = outgoingStatusRank[item.delivery_status || 'unknown'] || 0;
    const nextRank = outgoingStatusRank[status] || 0;
    if (nextRank < currentRank || (nextRank === currentRank && item.delivery_status && item.delivery_status !== 'unknown')) {
        return;
    }

    const fields = ['delivery_status = ?'];
    const values = [status];
    if (nextRank >= outgoingStatusRank.delivered && !item.delivered_at) {
        fields.push('delivered_at = NOW()');
    }
    if (nextRank >= outgoingStatusRank.read && !item.read_at) {
        fields.push('read_at = NOW()');
    }
    values.push(item.id);

    await db.query(
        `UPDATE whatsapp_dispatch_items SET ${fields.join(', ')} WHERE id = ?`,
        values
    );

    const messageFields = ['delivery_status = ?'];
    const messageValues = [status];
    if (nextRank >= outgoingStatusRank.delivered) messageFields.push('delivered_at = COALESCE(delivered_at, NOW())');
    if (nextRank >= outgoingStatusRank.read) messageFields.push('read_at = COALESCE(read_at, NOW())');
    messageValues.push(agentId, messageId);

    await db.query(
        `UPDATE messages
         SET ${messageFields.join(', ')}
         WHERE agent_id = ? AND whatsapp_message_id = ?`,
        messageValues
    );

    console.log(`📬 [${agentId}] Mensagem ${messageId} atualizada: ${status}`);
}

async function handleOutgoingMessageStatus(updates, agentId) {
    if (!await ensureBaileysProvider(agentId)) return;

    for (const update of updates || []) {
        const messageId = update?.key?.id;
        if (!messageId || update?.key?.fromMe === false) continue;

        const status = normalizeBaileysMessageStatus(update);
        if (!status) continue;

        try {
            await persistOutgoingMessageStatus(agentId, messageId, status);
        } catch (error) {
            console.warn(`⚠️ [${agentId}] Falha ao persistir status da mensagem ${messageId}:`, error.message);
        }
    }
}

async function handleOutgoingMessageReceipts(updates, agentId) {
    if (!await ensureBaileysProvider(agentId)) return;

    for (const update of updates || []) {
        const messageId = update?.key?.id;
        if (!messageId || update?.key?.fromMe === false) continue;

        const receipt = update?.receipt || {};
        const status = receipt.readTimestamp || receipt.readTimestampMs ? 'read' :
            (receipt.receiptTimestamp || receipt.receiptTimestampMs ? 'delivered' : null);
        if (!status) continue;

        try {
            await persistOutgoingMessageStatus(agentId, messageId, status);
        } catch (error) {
            console.warn(`⚠️ [${agentId}] Falha ao persistir recibo da mensagem ${messageId}:`, error.message);
        }
    }
}

function decodeBase64Url(value) {
    const normalized = String(value).replace(/-/g, '+').replace(/_/g, '/');
    return Buffer.from(normalized + '='.repeat((4 - normalized.length % 4) % 4), 'base64').toString('utf8');
}

function verifySocketToken(token) {
    const secret = process.env.WHATSAPP_SOCKET_SECRET || '';
    if (!secret || typeof token !== 'string') return null;

    const [encodedPayload, signature] = token.split('.');
    if (!encodedPayload || !signature) return null;

    const expected = crypto.createHmac('sha256', secret).update(encodedPayload).digest('hex');
    if (signature.length !== expected.length || !crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected))) {
        return null;
    }

    try {
        const payload = JSON.parse(decodeBase64Url(encodedPayload));
        if (!payload.sub || !Number.isInteger(payload.exp) || payload.exp <= Math.floor(Date.now() / 1000)) {
            return null;
        }
        return payload;
    } catch {
        return null;
    }
}

io.use((socket, next) => {
    const payload = verifySocketToken(socket.handshake.auth?.token);
    if (!payload) {
        return next(new Error('Socket não autenticado'));
    }

    socket.userId = String(payload.sub);
    socket.isAdmin = payload.admin === true;
    next();
});

// 🔐 MELHORIA: Rate limiting básico
const rateLimits = new Map(); // Armazena tentativas por IP/socket
const MAX_REQUESTS_PER_MINUTE = 60;
const RATE_LIMIT_WINDOW = 60 * 1000; // 1 minuto

// 🔧 SISTEMA DE API CENTRALIZADA
// Função para verificar se o sistema deve usar API centralizada (não validar usuário)
async function shouldUseSystemApi() {
    try {
        const [settingsRows] = await db.query('SELECT use_system_api FROM settings WHERE id = 1 LIMIT 1');

        if (!settingsRows || settingsRows.length === 0) {
            console.log('⚠️ Configuração use_system_api não encontrada');
            return false;
        }

        const useSystemApi = settingsRows[0].use_system_api;
        const result = useSystemApi === 1 || useSystemApi === '1' || useSystemApi === true || useSystemApi === 'true';

        console.log(`🔧 Verificação de API do sistema: ${useSystemApi} -> ${result}`);
        return result;
    } catch (error) {
        console.error('❌ Erro ao verificar configuração de API do sistema:', error.message);
        return false;
    }
}

/**
 * O Baileys e a Z-API compartilham a tabela de instâncias, mas nunca devem
 * disputar a mesma sessão. Este guard é consultado antes de criar cliente,
 * enviar mensagem ou executar recuperação automática.
 *
 * Instâncias legadas sem provider continuam sendo tratadas como Baileys.
 * Em caso de falha no banco, bloqueamos a ação para evitar envio pelo
 * provedor errado.
 */
async function getInstanceProvider(agentId, userId = null) {
    if (!agentId) return null;

    try {
        const conditions = ['agent_id = ?'];
        const params = [agentId];

        if (userId !== null && userId !== undefined) {
            conditions.push('user_id = ?');
            params.push(userId);
        }

        const [rows] = await db.query(
            `SELECT provider FROM whatsapp_instances WHERE ${conditions.join(' AND ')} LIMIT 1`,
            params
        );

        return String(rows?.[0]?.provider || 'baileys').toLowerCase();
    } catch (error) {
        console.error(`❌ [${agentId}] Não foi possível confirmar o provedor ativo:`, error.message);
        return null;
    }
}

async function ensureBaileysProvider(agentId, userId = null, socket = null) {
    const provider = await getInstanceProvider(agentId, userId);
    if (provider === 'baileys') return true;

    const message = provider === 'zapi'
        ? 'Esta instância está configurada para usar a Z-API.'
        : 'O provedor da instância não pôde ser confirmado com segurança.';

    console.warn(`⏭️ [${agentId}] Baileys bloqueado: provedor ativo=${provider || 'desconhecido'}`);

    if (socket) {
        socket.emit('status', {
            agent_id: agentId,
            status: 'provider_external',
            provider: provider || null,
            message,
            timestamp: Date.now()
        });
    }

    return false;
}

async function authorizeSocketAgent(socket, agentId) {
    if (!socket?.userId || !agentId || typeof agentId !== 'string') return null;

    try {
        const [rows] = await db.query(
            'SELECT uuid, user_id FROM agents WHERE uuid = ? AND status = ?',
            [agentId, 'enabled']
        );
        const agent = rows?.[0];

        if (!agent || (!socket.isAdmin && String(agent.user_id) !== String(socket.userId))) {
            return null;
        }

        return {
            agentId: agent.uuid,
            ownerUserId: agent.user_id,
        };
    } catch (error) {
        console.error(`❌ Falha ao autorizar agent ${agentId}:`, error.message);
        return null;
    }
}

function emitSocketAuthorizationError(socket, agentId) {
    socket.emit('status', {
        agent_id: agentId || null,
        status: 'error',
        error: 'Acesso não autorizado',
        timestamp: Date.now()
    });
}

// 🕐 VERIFICAÇÃO DE CLOCK DRIFT (OPCIONAL)
// Verifica se o relógio do servidor está sincronizado
// Clock drift pode causar erros 401 pois tokens assinados ficam inválidos
async function checkClockDrift() {
    try {
        // Verificar se fetch está disponível (Node.js 18+)
        if (typeof fetch === 'undefined') {
            console.log('ℹ️ Verificação de clock drift desabilitada (fetch não disponível)');
            return;
        }

        console.log('🕐 Verificando sincronização de relógio do servidor...');

        // Usar API pública para obter horário real
        const response = await fetch('https://worldtimeapi.org/api/timezone/America/Sao_Paulo', {
            signal: AbortSignal.timeout(5000) // Timeout de 5s
        });

        if (!response.ok) {
            console.log('ℹ️ Verificação de clock drift ignorada (API indisponível)');
            return;
        }

        const data = await response.json();
        const serverTime = Date.now();
        const realTime = new Date(data.datetime).getTime();
        const drift = Math.abs(serverTime - realTime);

        if (drift > 60000) { // Mais de 1 minuto de diferença
            console.warn('⚠️ ═══════════════════════════════════════════════════════════');
            console.warn(`⚠️ CLOCK DRIFT DETECTADO: ${Math.round(drift / 1000)}s de diferença`);
            console.warn('⚠️ Isso PODE CAUSAR ERROS 401 no WhatsApp!');
            console.warn('⚠️ Solução: Sincronizar relógio do servidor');
            console.warn('⚠️ Windows: Configurações > Hora e Idioma > Sincronizar agora');
            console.warn('⚠️ Linux: timedatectl set-ntp true');
            console.warn('⚠️ ═══════════════════════════════════════════════════════════');
        } else {
            console.log(`✅ Relógio sincronizado (drift: ${drift}ms)`);
        }
    } catch (error) {
        // Silenciar erros de rede - verificação é opcional
        if (error.name === 'AbortError') {
            console.log('ℹ️ Verificação de clock drift timeout (ignorado)');
        } else if (error.message?.includes('fetch')) {
            console.log('ℹ️ Verificação de clock drift desabilitada (fetch não disponível)');
        } else {
            console.log(`ℹ️ Verificação de clock drift ignorada: ${error.message}`);
        }
    }
}

// Executar verificação de clock drift ao iniciar (não bloqueia se falhar)
checkClockDrift().catch(() => {
    // Ignorar silenciosamente - verificação é opcional
});

function checkRateLimit(socketId, action = 'general') {
    const key = `${socketId}_${action}`;
    const now = Date.now();
    const windowStart = now - RATE_LIMIT_WINDOW;

    if (!rateLimits.has(key)) {
        rateLimits.set(key, []);
    }

    const attempts = rateLimits.get(key);
    // Remove tentativas antigas
    const recentAttempts = attempts.filter(time => time > windowStart);

    if (recentAttempts.length >= MAX_REQUESTS_PER_MINUTE) {
        console.warn(`🚫 Rate limit excedido para ${socketId} na ação ${action}`);
        return false;
    }

    recentAttempts.push(now);
    rateLimits.set(key, recentAttempts);
    return true;
}

// Cache para evitar processamento duplicado de mensagens (por agent)
const processedMessages = new Map(); // agentId -> Set of messageIds

// 🛡️ Proteção contra processamento duplicado de desconexões
const disconnectionProcessing = new Map(); // agentId -> timestamp da última desconexão processada
const DISCONNECTION_DEBOUNCE = 15000; // 🆕 AUMENTADO para 15 segundos - evitar processar desconexões muito próximas

// 🆕 SISTEMA DE MONITORAMENTO BASEADO NO BAILEYS (independente do WebSocket)
const baileysStatusMonitor = new Map(); // agentId -> { lastHeartbeat, realStatus, lastUpdate }
const BAILEYS_HEARTBEAT_INTERVAL = 15000; // 🆕 REDUZIDO para 15 segundos - intervalo de heartbeat mais frequente
const BAILEYS_STATUS_TIMEOUT = 90000; // 🆕 REDUZIDO para 90 segundos - timeout mais rápido para detectar problemas

// 🛡️ RATE LIMITING DE MENSAGENS DE SAÍDA
// O limite é por instância (não por socket), incluindo respostas automáticas,
// painel e disparos encaminhados pelo Laravel.
const messageRateLimit = new Map(); // agentId -> { count, pending, windowStart }
const MAX_MESSAGES_PER_MINUTE = 10;
const MINUTE_WINDOW = 60 * 1000; // 1 minuto


// 🚫 PALAVRAS E PADRÕES SUSPEITOS PARA FILTRAR
const SUSPICIOUS_PATTERNS = [
    // 🆕 FILTRO RELAXADO: Permitir links e números para interação normal
    // /https?:\/\//i,           // Links HTTP/HTTPS
    // /bit\.ly/i,              // URLs encurtadas
    // /tinyurl\.com/i,         // URLs encurtadas
    // /cupom/i,                // Cupons/promocionais
    // /desconto/i,             // Descontos
    // /gratuito/i,             // Gratuito
    // /prêmio/i,               // Prêmios
    // /ganhou/i,               // Ganhou
    // /sorteio/i,              // Sorteios
    // /promoção/i,             // Promoções
    // /oferta/i,               // Ofertas
    // /\b\d{4,}\b/,           // Sequências longas de números (possíveis códigos)
    // /[A-Z]{5,}/,            // MAIÚSCULAS EM SEQUÊNCIA (GRITANDO)
    // /[!]{3,}/,              // Múltiplos pontos de exclamação
    // /[\?]{3,}/,             // Múltiplas interrogações
];

// 📨 FILA DE MENSAGENS PENDENTES PARA RECONEXÃO
const pendingMessages = new Map(); // agentId -> [{payload, response, timestamp}, ...]

// ✅ Map para armazenar keys em memória durante handshake
// Keys só são salvas no banco após conexão estar estabelecida
// ✅ 3️⃣ Formato: agentId -> { data: keys, updatedAt: timestamp }
const keysCache = new Map();

// Mapeamento de contatos: phone -> @lid
const contactMapping = new Map(); // agentId -> Map<phone, lid>

// Cache para evitar envios duplicados
const sentResponses = new Map(); // agentId -> Set of responseIds

// Cache global para evitar processamento duplicado de mensagens
const processedMessagesGlobal = new Map(); // agentId -> Set of messageIds

// Atualizar mapeamento de contato
function updateContactMapping(agentId, phone, lid) {
    if (!phone || !lid) return;

    if (!contactMapping.has(agentId)) {
        contactMapping.set(agentId, new Map());
    }

    contactMapping.get(agentId).set(phone, lid);
}

// ✅ 3️⃣ TTL: Limpeza automática de cache expirado (5 minutos)
setInterval(() => {
    const now = Date.now();
    const TTL_MS = 5 * 60 * 1000; // 5 minutos

    for (const [agentId, entry] of keysCache.entries()) {
        // Suportar formato antigo (keys direto) e novo (com TTL)
        let updatedAt = null;
        if (entry && typeof entry === 'object' && 'updatedAt' in entry) {
            // Formato novo: { data: keys, updatedAt: timestamp }
            updatedAt = entry.updatedAt;
        } else {
            // Formato antigo: keys direto - considerar expirado imediatamente
            updatedAt = 0;
        }

        if (updatedAt !== null && (now - updatedAt > TTL_MS)) {
            keysCache.delete(agentId);
            console.log(`🧹 Cache de keys expirado removido para ${agentId}`);
        }
    }
}, 60_000); // Verificar a cada 1 minuto

// 🆕 REMOVIDO: Sistema de monitoramento baseado no Baileys (interval de 30s)
// Agora confiamos apenas nos callbacks do Baileys (onBaileysStatusChange, onBaileysHeartbeat)
// para atualizar status no banco. Isso evita conflitos e atualizações redundantes.

// ✅ 8️⃣ CONNECTION HEALTH MONITOR: Detecção de conexões travadas e verificação proativa de saúde
// 🆕 MELHORADO: Verificação menos frequente para reduzir overhead
setInterval(async () => {
    try {
        console.log('🏥 [HEALTH MONITOR] Verificando saúde das conexões...');

        // 🆕 Verificar conexões que estão marcadas como connected mas podem estar desconectadas
        const [connectedInstances] = await db.query(
            `SELECT uuid, agent_id, user_id, status, updated_at, last_activity
             FROM whatsapp_instances
             WHERE status = 'connected'
             AND provider = 'baileys'
             AND (updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                  OR last_activity < DATE_SUB(NOW(), INTERVAL 5 MINUTE))`,
            []
        );

        // Verificar se conexões marcadas como connected estão realmente ativas
        for (const instance of connectedInstances || []) {
            const client = clients.get(instance.agent_id);

            // 🆕 VERIFICAÇÃO MAIS ROBUSTA: Evitar falsos positivos
            // Só considerar desconectado se TODAS as condições falharem
            const isReallyConnected = client && client.isConnected && client._connectionEstablished;
            const wsReadyState = client?.socket?.ws?.readyState;
            const hasActiveSocket = client?.socket && typeof client.socket.ev === 'object';
            const hasOpenSocket = hasActiveSocket && wsReadyState === 1;

            // Flags inconsistentes com socket aberto devem ser sincronizadas;
            // socket fechado/ausente deve iniciar reconexão. Antes, um socket
            // existente com flags falsas era considerado saudável por engano.
            const needsRecovery = !isReallyConnected && !hasOpenSocket;

            if (!isReallyConnected && hasOpenSocket) {
                console.log(`🔄 [HEALTH MONITOR] ${instance.agent_id} com flags dessincronizadas - sincronizando conexão aberta`);
                try {
                    await client.handleConnectionEstablished();
                } catch (error) {
                    console.error(`❌ [HEALTH MONITOR] Erro ao sincronizar ${instance.agent_id}:`, error.message);
                }
            } else if (needsRecovery) {
                console.log(`⚠️ [HEALTH MONITOR] Instância ${instance.agent_id} realmente desconectada - tentando recuperar`);
                console.log(`   └─ isConnected: ${client?.isConnected}, _connectionEstablished: ${client?._connectionEstablished}, wsReadyState: ${wsReadyState}, hasSocket: ${hasActiveSocket}`);

                if (client) {
                    try {
                        if (!['qr', 'connecting', 'initializing'].includes(client.currentStatus)) {
                            // O cliente mantém o backoff e o lock da reconexão.
                            void client._attemptReconnect(0);
                            console.log(`✅ [HEALTH MONITOR] Reconexão solicitada para ${instance.agent_id}`);
                        } else {
                            console.log(`⏭️ [HEALTH MONITOR] ${instance.agent_id} em estado transitório (${client.currentStatus}); aguardando`);
                        }
                    } catch (error) {
                        console.error(`❌ [HEALTH MONITOR] Erro ao recuperar ${instance.agent_id}:`, error.message);
                    }
                } else {
                    // Se não tem client, criar um novo
                    console.log(`🔄 [HEALTH MONITOR] Cliente não existe - inicializando nova sessão para ${instance.agent_id}`);
                    try {
                        // ✅ Modo recuperação: preserva credenciais em vez de forçar novo QR
                        await safeInitializeSession(instance.agent_id, instance.user_id, null, { freshSession: false });
                    } catch (error) {
                        console.error(`❌ [HEALTH MONITOR] Erro ao inicializar ${instance.agent_id}:`, error.message);
                    }
                }
            } else if (isReallyConnected && wsReadyState !== 1) {
                // Conexão ativa mas wsReadyState temporariamente diferente - apenas log
                console.log(`ℹ️ [HEALTH MONITOR] ${instance.agent_id} conectado mas wsReadyState=${wsReadyState} (ignorando - falso positivo)`);
            }
        }

        // Buscar instâncias em connecting há mais de 10 minutos (aumentado de 5)
        const [stuckConnections] = await db.query(
            `SELECT uuid, agent_id, user_id, status, updated_at
             FROM whatsapp_instances
             WHERE status = 'connecting'
             AND provider = 'baileys'
             AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)`,
            []
        );

        if ((!stuckConnections || stuckConnections.length === 0) &&
            (!connectedInstances || connectedInstances.length === 0)) {
            console.log('✅ [HEALTH MONITOR] Todas as conexões estão saudáveis');
            return;
        }

        console.log(`🚨 [HEALTH MONITOR] Encontradas ${stuckConnections.length} conexões travadas`);

        for (const instance of stuckConnections) {
            try {
                const agentId = instance.agent_id;

                // 🆕 VERIFICAR STATUS REAL DO BAILEYS ANTES DE RESETAR
                const monitorData = baileysStatusMonitor.get(agentId);
                const client = clients.get(agentId);

                // Se o Baileys está realmente conectado, apenas atualizar banco
                if (client && client.isConnected && client._connectionEstablished) {
                    console.log(`✅ [${agentId}] Baileys está conectado - atualizando banco apenas`);
                    try {
                        await db.query(
                            `UPDATE whatsapp_instances
                             SET status = 'connected',
                                 updated_at = NOW()
                             WHERE uuid = ?`,
                            [instance.uuid]
                        );
                    } catch (dbError) {
                        console.warn(`⚠️ [${agentId}] Erro ao atualizar banco:`, dbError.message);
                    }
                    continue; // Não resetar se está conectado
                }

                console.log(`🔧 [HEALTH MONITOR] Resetando conexão travada: ${agentId}`);

                // Limpar recursos sem logout. Uma conexão travada é um
                // problema transitório; logout apagaria as chaves e forçaria
                // novo QR sem confirmação de sessão inválida.
                await cleanupClient(agentId, 'stuck_connection');

                // Remover do monitoramento
                baileysStatusMonitor.delete(agentId);

                // Resetar no banco com tratamento de erro
                try {
                    await db.query(
                        `UPDATE whatsapp_instances
                         SET status = 'disconnected',
                             qr_code = NULL,
                             error_message = 'Conexão resetada automaticamente (travada)',
                             updated_at = NOW()
                         WHERE uuid = ?`,
                        [instance.uuid]
                    );
                    console.log(`✅ [HEALTH MONITOR] Conexão ${instance.agent_id} resetada com sucesso`);
                } catch (dbError) {
                    console.error(`❌ [HEALTH MONITOR] Erro ao atualizar banco para ${instance.agent_id}:`, dbError.message);
                    // Continua mesmo com erro no banco
                }

                // Tentar recuperar usando as credenciais preservadas. Se a
                // sessão não existir mais, o fluxo apenas emitirá um novo QR;
                // nenhum arquivo é apagado por este caminho automático.
                try {
                    await safeInitializeSession(agentId, instance.user_id, null, { freshSession: false });
                    console.log(`🔄 [HEALTH MONITOR] Recuperação preservando credenciais solicitada para ${agentId}`);
                } catch (recoveryError) {
                    console.warn(`⚠️ [HEALTH MONITOR] Não foi possível solicitar recuperação para ${agentId}:`, recoveryError.message);
                }

            } catch (error) {
                console.error(`❌ [HEALTH MONITOR] Erro ao resetar conexão ${instance.agent_id}:`, error.message);
            }
        }

        console.log('✅ [HEALTH MONITOR] Monitoramento de conexões concluído');

    } catch (error) {
        console.error('❌ [HEALTH MONITOR] Erro no monitoramento de conexões:', error.message);
        console.error('❌ [HEALTH MONITOR] Stack trace:', error.stack);

        // 🆕 Em caso de erro crítico, não parar o serviço
        console.log('🔄 [HEALTH MONITOR] Continuando operação apesar do erro...');
    }
}, 10 * 60 * 1000); // 🆕 AUMENTADO para 10 minutos (reduzir overhead)

// ✅ 9️⃣ KEEP-ALIVE RÁPIDO: Reativar instâncias com flags dessincronizadas (a cada 2 min)
setInterval(async () => {
    try {
        console.log('🔄 [KEEP-ALIVE] Verificando instâncias em memória...');

        let reactivated = 0;
        let skipped = 0;

        for (const [agentId, client] of clients.entries()) {
            // 🆕 VERIFICAÇÃO CONSERVADORA: Só reativar se REALMENTE necessário
            // Evitar reconexões desnecessárias que podem causar ban do WhatsApp

            // Reconciliar qualquer estado que não esteja plenamente conectado.
            // Antes, o verificador só tratava `_connectionEstablished=true` e
            // `isConnected=false`, deixando silenciosamente sem recuperação o
            // caso em que ambas as flags eram zeradas pelo heartbeat.
            if (!client.isConnected || !client._connectionEstablished) {
                const wsReadyState = client.socket?.ws?.readyState;
                const hasActiveSocket = client.socket && typeof client.socket.ev === 'object';

                if (wsReadyState === 1 && hasActiveSocket) {
                    console.log(`🔄 [KEEP-ALIVE] ${agentId} - sincronizando estado (WebSocket ativo)`);
                    try {
                        await client.handleConnectionEstablished();
                        reactivated++;
                    } catch (error) {
                        console.error(`❌ [KEEP-ALIVE] Erro ao sincronizar ${agentId}:`, error.message);
                    }
                } else if (
                    (wsReadyState === 3 || !hasActiveSocket) &&
                    !['qr', 'connecting', 'initializing'].includes(client.currentStatus)
                ) {
                    console.log(`🔄 [KEEP-ALIVE] ${agentId} - socket indisponível, solicitando reconexão (wsReadyState: ${wsReadyState})`);
                    try {
                        // O próprio BaileysClient aplica lock, debounce e
                        // backoff. Não criar outro cliente nem apagar credenciais.
                        void client._attemptReconnect(0);
                        reactivated++;
                    } catch (error) {
                        console.error(`❌ [KEEP-ALIVE] Erro ao solicitar reconexão ${agentId}:`, error.message);
                    }
                } else {
                    console.log(`⏭️ [KEEP-ALIVE] ${agentId} - estado transitório, aguardando (wsReadyState: ${wsReadyState}, status: ${client.currentStatus})`);
                    skipped++;
                }
            }
        }

        if (reactivated > 0) {
            console.log(`✅ [KEEP-ALIVE] ${reactivated} flag(s) sincronizada(s), ${skipped} aguardando verificação profunda`);
        } else if (skipped > 0) {
            console.log(`⏭️ [KEEP-ALIVE] ${skipped} instância(s) aguardando Keep-Alive Profundo`);
        } else {
            console.log(`✅ [KEEP-ALIVE] Todas as ${clients.size} instância(s) ativa(s)`);
        }
    } catch (error) {
        console.error('❌ [KEEP-ALIVE] Erro:', error.message);
    }
}, 2 * 60 * 1000); // 2 minutos

// ✅ 🔟 KEEP-ALIVE PROFUNDO: Testar e reativar instâncias conectadas (a cada 15 min)
setInterval(async () => {
    try {
        console.log('🔍 [KEEP-ALIVE DEEP] Verificando saúde profunda das instâncias...');

        // Buscar instâncias marcadas como connected
        const [connectedInstances] = await db.query(
            `SELECT uuid, agent_id, user_id, status, phone_number
             FROM whatsapp_instances
             WHERE status = 'connected'
             AND provider = 'baileys'`,
            []
        );

        if (!connectedInstances || connectedInstances.length === 0) {
            console.log('ℹ️ [KEEP-ALIVE DEEP] Nenhuma instância conectada');
            return;
        }

        console.log(`🔍 [KEEP-ALIVE DEEP] Testando ${connectedInstances.length} instância(s)...`);

        let healthy = 0;
        let reactivated = 0;
        let initialized = 0;

        for (const instance of connectedInstances) {
            const client = clients.get(instance.agent_id);

            if (!client) {
                console.log(`⚠️ [KEEP-ALIVE DEEP] ${instance.agent_id} sem cliente em memória - inicializando...`);
                try {
                    // ✅ Modo recuperação: preserva credenciais em vez de forçar novo QR
                    await safeInitializeSession(instance.agent_id, instance.user_id, null, { freshSession: false });
                    initialized++;
                } catch (error) {
                    console.error(`❌ [KEEP-ALIVE DEEP] Erro ao inicializar ${instance.agent_id}:`, error.message);
                }
                continue;
            }

            // 🆕 VERIFICAÇÃO CONSERVADORA: Testar conexão real
            const isConnected = client.isConnected;
            const isEstablished = client._connectionEstablished;
            const wsReadyState = client.socket?.ws?.readyState;
            const hasActiveSocket = client.socket && typeof client.socket.ev === 'object';

            // Considerar saudável se:
            // 1. isConnected E _connectionEstablished estão true, OU
            // 2. WebSocket está ativo (readyState = 1) E tem socket ativo
            const isHealthy = (isConnected && isEstablished) || (wsReadyState === 1 && hasActiveSocket);

            if (!isHealthy) {
                // 🆕 VERIFICAR SE WEBSOCKET ESTÁ REALMENTE MORTO
                // Se WebSocket está em estado transitório (0 = CONNECTING, 2 = CLOSING), aguardar
                if (wsReadyState === 0 || wsReadyState === 2) {
                    console.log(`⏳ [KEEP-ALIVE DEEP] ${instance.agent_id} em estado transitório (wsReadyState: ${wsReadyState}) - aguardando...`);
                    continue;
                }

                // Se WebSocket está CLOSED (3) ou undefined, tentar reativar
                console.log(`🔄 [KEEP-ALIVE DEEP] ${instance.agent_id} não saudável - tentando reativar...`);
                console.log(`   └─ isConnected: ${isConnected}, _connectionEstablished: ${isEstablished}, wsReadyState: ${wsReadyState}, hasSocket: ${hasActiveSocket}`);

                try {
                    // 🆕 APENAS sincronizar flags se WebSocket está ativo
                    // Se WebSocket morto, NÃO fazer nada - deixar Auto Recovery cuidar
                    if (hasActiveSocket && wsReadyState === 1) {
                        // WebSocket ativo mas flags dessincronizadas - apenas sincronizar
                        console.log(`🔄 [KEEP-ALIVE DEEP] ${instance.agent_id} - sincronizando flags (WebSocket ativo)`);
                        await client.handleConnectionEstablished();
                        reactivated++;
                    } else {
                        // WebSocket morto - NÃO fazer nada, deixar Auto Recovery cuidar
                        console.log(`⏭️ [KEEP-ALIVE DEEP] ${instance.agent_id} - WebSocket morto, aguardando Auto Recovery`);
                    }
                } catch (error) {
                    console.error(`❌ [KEEP-ALIVE DEEP] Erro ao reativar ${instance.agent_id}:`, error.message);
                }
            } else {
                healthy++;
            }
        }

        console.log(`✅ [KEEP-ALIVE DEEP] Verificação concluída - Saudáveis: ${healthy}, Reativadas: ${reactivated}, Inicializadas: ${initialized}`);

    } catch (error) {
        console.error('❌ [KEEP-ALIVE DEEP] Erro crítico:', error.message);
    }
}, 15 * 60 * 1000); // 15 minutos


// ✅ 4️⃣ TRIAL VALIDATION SERVICE: Gerenciamento automático de instâncias de teste
setInterval(async () => {
    try {
        // Buscar instâncias de teste que expiraram
        // ✅ CORREÇÃO: Filtrar apenas instâncias com agent_id (este servidor é para agentes)
        // ✅ CORREÇÃO: Buscar todas as instâncias expiradas, não apenas connected/connecting
        const [expiredTrials] = await db.query(
            `SELECT uuid, agent_id, user_id, trial_expires_at, status
             FROM whatsapp_instances
             WHERE is_trial = 1
             AND provider = 'baileys'
             AND agent_id IS NOT NULL
             AND trial_expires_at IS NOT NULL
             AND trial_expires_at < NOW()
             AND status != 'expired'`,
            []
        );

        if (!expiredTrials || expiredTrials.length === 0) {
            // Silencioso - não logar verificações rotineiras sem ação
            return;
        }

        console.log(`🚨 [TRIAL SERVICE] Encontradas ${expiredTrials.length} instâncias de teste expiradas`);

        for (const instance of expiredTrials) {
            try {
                // ✅ CORREÇÃO: Validar se agent_id existe antes de processar
                if (!instance.agent_id) {
                    console.warn(`⚠️ [TRIAL SERVICE] Instância ${instance.uuid} sem agent_id - pulando`);
                    // Mesmo assim, marcar como expirada no banco
                    try {
                        await db.query(
                            `UPDATE whatsapp_instances
                             SET status = 'expired',
                                 error_message = 'Instância de teste expirada após 48 horas',
                                 updated_at = NOW()
                             WHERE uuid = ?`,
                            [instance.uuid]
                        );
                    } catch (dbError) {
                        console.error(`❌ [TRIAL SERVICE] Erro ao atualizar banco para ${instance.uuid}:`, dbError.message);
                    }
                    continue;
                }

                console.log(`⏰ [TRIAL SERVICE] Desconectando instância expirada: ${instance.agent_id} (${instance.uuid})`);

                // Verificar se a instância ainda está ativa no servidor
                const client = clients.get(instance.agent_id);
                if (client) {
                    // Desconectar o cliente Baileys (sem log detalhado)
                    try {
                        await client.logout();
                        console.log(`✅ [TRIAL SERVICE] Logout realizado para ${instance.agent_id}`);
                    } catch (logoutError) {
                        console.warn(`⚠️ [TRIAL SERVICE] Erro no logout de ${instance.agent_id}:`, logoutError.message);
                        try {
                            await client.disconnect();
                            console.log(`✅ [TRIAL SERVICE] Disconnect realizado para ${instance.agent_id}`);
                        } catch (disconnectError) {
                            console.warn(`⚠️ [TRIAL SERVICE] Erro na desconexão de ${instance.agent_id}:`, disconnectError.message);
                        }
                    }
                } else {
                    console.log(`ℹ️ [TRIAL SERVICE] Cliente ${instance.agent_id} não encontrado em memória (já desconectado)`);
                }

                // ✅ CORREÇÃO: Usar cleanupClient para limpeza completa (mesmo se cliente não existir)
                await cleanupClient(instance.agent_id, 'trial_expired');

                // ✅ CORREÇÃO: Atualizar status no banco ANTES de notificar WebSocket
                try {
                    await db.query(
                        `UPDATE whatsapp_instances
                         SET status = 'expired',
                             error_message = 'Instância de teste expirada após 48 horas',
                             qr_code = NULL,
                             phone_number = NULL,
                             updated_at = NOW()
                         WHERE uuid = ?`,
                        [instance.uuid]
                    );
                    console.log(`✅ [TRIAL SERVICE] Instância ${instance.agent_id} (${instance.uuid}) marcada como expirada no banco`);
                } catch (dbError) {
                    console.error(`❌ [TRIAL SERVICE] Erro ao atualizar banco para ${instance.agent_id}:`, dbError.message);
                    // Continua mesmo com erro no banco
                }

                // Notificar via WebSocket se houver sockets conectados
                io.to(instance.agent_id).emit('status', {
                    agent_id: instance.agent_id,
                    status: 'expired',
                    message: 'Instância de teste expirada após 48 horas. Para continuar, assine um plano.',
                    requires_new_qr: true,
                    timestamp: Date.now()
                });

            } catch (error) {
                console.error(`❌ [TRIAL SERVICE] Erro ao processar instância expirada ${instance.agent_id || instance.uuid}:`, error.message);
                console.error(`❌ [TRIAL SERVICE] Stack trace:`, error.stack);
                // ✅ CORREÇÃO: Tentar marcar como expirada no banco mesmo em caso de erro
                try {
                    await db.query(
                        `UPDATE whatsapp_instances
                         SET status = 'expired',
                             error_message = 'Instância de teste expirada após 48 horas (processamento com erro)',
                             updated_at = NOW()
                         WHERE uuid = ?`,
                        [instance.uuid]
                    );
                } catch (dbError) {
                    console.error(`❌ [TRIAL SERVICE] Erro crítico ao atualizar banco:`, dbError.message);
                }
            }
        }

    } catch (error) {
        console.error('❌ [TRIAL SERVICE] Erro crítico no serviço de validação de testes:', error.message);
        console.error('❌ [TRIAL SERVICE] Stack trace:', error.stack);

        // 🆕 Em caso de erro crítico, não parar o serviço
        console.log('🔄 [TRIAL SERVICE] Continuando operação apesar do erro...');
    }
}, 5 * 60 * 1000); // Executar a cada 5 minutos

// ✅ 5️⃣ AUTO RECOVERY SERVICE: Recuperação automática de instâncias ativas no restart
async function autoRecoverInstances() {
    try {
        console.log('🔄 [AUTO RECOVERY] Iniciando recuperação de instâncias ativas...');

        // Buscar instâncias que estavam conectadas antes do restart
        // ⚠️ IMPORTANTE: Não recuperar instâncias 'disconnected' - deixar que gerem novo QR
        const [activeInstances] = await db.query(
            `SELECT wi.uuid, wi.agent_id, wi.user_id, wi.status, wi.is_trial, wi.trial_expires_at,
                    a.uuid as agent_uuid, a.name as agent_name, a.status as agent_status
             FROM whatsapp_instances wi
             INNER JOIN agents a ON wi.agent_id = a.uuid
             WHERE wi.status IN ('connected', 'connecting')
             AND wi.provider = 'baileys'
             AND a.status = 'enabled'`,
            []
        );

        if (!activeInstances || activeInstances.length === 0) {
            console.log('ℹ️ [AUTO RECOVERY] Nenhuma instância ativa encontrada para recuperação');
            return;
        }

        console.log(`📡 [AUTO RECOVERY] Encontradas ${activeInstances.length} instâncias para recuperação`);

        for (const instance of activeInstances) {
            try {
                // Verificar se é uma instância de teste expirada
                const now = new Date();
                const isTrialExpired = instance.is_trial && instance.trial_expires_at &&
                    new Date(instance.trial_expires_at) < now;

                if (isTrialExpired) {
                    console.log(`⏰ [AUTO RECOVERY] Instância de teste expirada: ${instance.agent_id}`);

                    // Marcar como expirada no banco
                    await db.query(
                        `UPDATE whatsapp_instances
                         SET status = 'expired',
                             error_message = 'Instância de teste expirada após 48 horas',
                             updated_at = NOW()
                         WHERE uuid = ?`,
                        [instance.uuid]
                    );
                    continue;
                }

                // Verificar se já existe uma sessão ativa para este agent
                if (clients.has(instance.agent_id)) {
                    console.log(`⚠️ [AUTO RECOVERY] Cliente ${instance.agent_id} já existe, pulando...`);
                    continue;
                }

                // 🆕 VALIDAÇÃO DE CREDENCIAIS ANTES DE TENTAR RECUPERAR
                const authFolder = authFolderFor(instance.agent_id);
                const credsPath = path.join(authFolder, 'creds.json');

                // Verificar se credenciais existem
                if (!fs.existsSync(credsPath)) {
                    console.log(`📁 [AUTO RECOVERY] Credenciais não encontradas para ${instance.agent_id} - marcando como desconectada`);

                    // Marcar como desconectada no banco (instância inválida)
                    await db.query(
                        `UPDATE whatsapp_instances
                         SET status = 'disconnected',
                             error_message = 'Credenciais não encontradas após restart do servidor',
                             qr_code = NULL,
                             phone_number = NULL,
                             updated_at = NOW()
                         WHERE uuid = ?`,
                        [instance.uuid]
                    );
                    continue;
                }

                // 🆕 Validar se credenciais são válidas (JSON válido e estrutura correta)
                try {
                    const credsData = fs.readFileSync(credsPath, 'utf8');
                    const creds = JSON.parse(credsData);

                    // Verificar estrutura básica das credenciais
                    if (!creds || typeof creds !== 'object') {
                        throw new Error('Credenciais inválidas: não é um objeto');
                    }

                    // Verificar se credenciais não estão muito antigas (mais de 30 dias sem uso)
                    const lastAuthTime = creds.lastAuthTime || 0;
                    const thirtyDaysAgo = Date.now() - (30 * 24 * 60 * 60 * 1000);
                    if (lastAuthTime > 0 && lastAuthTime < thirtyDaysAgo) {
                        console.log(`⏰ [AUTO RECOVERY] Credenciais muito antigas para ${instance.agent_id} - marcando como desconectada`);

                        // Marcar como desconectada (sessão expirada)
                        await db.query(
                            `UPDATE whatsapp_instances
                             SET status = 'disconnected',
                                 error_message = 'Sessão expirada - credenciais muito antigas',
                                 qr_code = NULL,
                                 phone_number = NULL,
                                 updated_at = NOW()
                             WHERE uuid = ?`,
                            [instance.uuid]
                        );
                        continue;
                    }

                    console.log(`✅ [AUTO RECOVERY] Credenciais válidas encontradas para ${instance.agent_id} - tentando recuperar`);
                } catch (credsError) {
                    console.log(`❌ [AUTO RECOVERY] Credenciais inválidas/corrompidas para ${instance.agent_id}: ${credsError.message}`);

                    // Marcar como desconectada e limpar credenciais corrompidas
                    await db.query(
                        `UPDATE whatsapp_instances
                         SET status = 'disconnected',
                             error_message = 'Credenciais corrompidas após restart do servidor',
                             qr_code = NULL,
                             phone_number = NULL,
                             updated_at = NOW()
                         WHERE uuid = ?`,
                        [instance.uuid]
                    );

                    // Colocar credenciais corrompidas em quarentena, sem
                    // apagar a única cópia automaticamente.
                    try {
                        quarantineAuthFolder(instance.agent_id, 'auto_recovery_invalid');
                    } catch (quarantineError) {
                        console.warn(`⚠️ [AUTO RECOVERY] Erro ao colocar credenciais em quarentena: ${quarantineError.message}`);
                    }
                    continue;
                }


                // Criar configuração para reconexão
                const config = {
                    userId: instance.user_id,
                    agentId: instance.agent_id,
                    connection: db,

                    // Callbacks para comunicação
                    onQr: (qr, agentId) => {
                        console.log(`📱 [AUTO RECOVERY] QR gerado para ${agentId} durante recuperação`);
                        // Durante recuperação, não emitir QR - apenas log
                    },

                    /**
                     * CALLBACK: onConnected
                     * Chamado quando o WhatsApp conecta com sucesso
                     * Atualiza status e notifica frontend
                     */
                    onConnected: (agentId, phoneNumber, instanceLid) => {
                        console.log(`✅ [AUTO RECOVERY] Instância ${agentId} recuperada com sucesso - LID: ${instanceLid}`);

                        // 🆕 ARMAZENAR @LID DA INSTÂNCIA PARA VALIDAÇÃO DE SELF-MESSAGES
                        if (instanceLid) {
                            clients.get(agentId).instanceLid = instanceLid;
                        }

                        // 📤 PROCESSAR MENSAGENS PENDENTES APÓS RECONEXÃO
                        setTimeout(() => {
                            processPendingMessages(agentId);
                        }, 3000); // Aguardar 3 segundos para estabilizar
                    },

                    /**
                     * CALLBACK: onDisconnected
                     * Chamado quando o WhatsApp desconecta
                     * Gerencia reconexão automática e limpeza de recursos
                     * 🆕 Parâmetro shouldInvalidateInstance: indica se a instância deve ser invalidada
                     */
                    onDisconnected: async (agentId, reason, canReconnect = false, shouldInvalidateInstance = false) => {
                        console.log(`🔌 [AUTO RECOVERY] Instância ${agentId} desconectada durante recuperação: ${reason} (invalidar: ${shouldInvalidateInstance})`);

                        // Se deve invalidar, atualizar banco de dados
                        if (shouldInvalidateInstance) {
                            try {
                                await db.query(
                                    `UPDATE whatsapp_instances
                                     SET status = 'disconnected',
                                         error_message = ?,
                                         qr_code = NULL,
                                         phone_number = NULL,
                                         updated_at = NOW()
                                     WHERE agent_id = ?`,
                                    [`Sessão invalidada durante recuperação: ${reason}`, agentId]
                                );
                            } catch (dbError) {
                                console.warn(`⚠️ [AUTO RECOVERY] Erro ao atualizar banco: ${dbError.message}`);
                            }
                        }
                    },

                    /**
                     * CALLBACK: onMessage
                     * Chamado quando uma mensagem é recebida via WhatsApp
                     * Processa mensagens recebidas e as envia para o Laravel
                     */
                    onMessage: async (msg, agentId) => {
                        await handleIncomingMessageBaileys(msg, agentId);
                    },

                    onMessageStatusUpdate: async (updates, agentId) => {
                        await handleOutgoingMessageStatus(updates, agentId);
                    },

                    onMessageReceiptUpdate: async (updates, agentId) => {
                        await handleOutgoingMessageReceipts(updates, agentId);
                    }
                };

                // Criar e inicializar cliente Baileys
                const client = new BaileysClient(config);

                // Tentar reconectar (credenciais já foram validadas acima)
                await client.initialize(true); // isReconnect = true

                // Verificar se a conexão foi estabelecida com sucesso
                if (client.isConnected) {
                    // Adicionar ao Map de clientes
                    clients.set(instance.agent_id, client);
                    statuses.set(instance.agent_id, 'connected');

                    console.log(`✅ [AUTO RECOVERY] Instância ${instance.agent_id} recuperada com sucesso`);
                } else {
                    // Cliente criado mas não conectado (pode estar aguardando QR ou erro)
                    console.log(`⚠️ [AUTO RECOVERY] Instância ${instance.agent_id} inicializada mas não conectada ainda`);

                    // Adicionar ao Map mesmo assim (pode conectar depois)
                    clients.set(instance.agent_id, client);
                    statuses.set(instance.agent_id, 'connecting');
                }

            } catch (error) {
                console.error(`❌ [AUTO RECOVERY] Erro ao recuperar instância ${instance.agent_id}:`, error.message);
                console.error(`❌ [AUTO RECOVERY] Stack trace:`, error.stack);

                // Determinar se é erro recuperável ou não
                const isRecoverableError = error.message && (
                    error.message.includes('timeout') ||
                    error.message.includes('network') ||
                    error.message.includes('ECONNREFUSED') ||
                    error.message.includes('temporarily')
                );

                if (!isRecoverableError) {
                    // Erro não recuperável - marcar como desconectada e limpar credenciais se necessário
                    const errorMessage = error.message.includes('corrupt') || error.message.includes('invalid') || error.message.includes('expired')
                        ? `Credenciais inválidas: ${error.message}`
                        : `Erro na recuperação automática: ${error.message}`;

                    await db.query(
                        `UPDATE whatsapp_instances
                         SET status = 'disconnected',
                             error_message = ?,
                             qr_code = NULL,
                             phone_number = NULL,
                             updated_at = NOW()
                         WHERE uuid = ?`,
                        [errorMessage, instance.uuid]
                    );

                    // Se o erro indica credenciais corrompidas, colocar a
                    // sessão em quarentena em vez de apagá-la definitivamente.
                    if (error.message.includes('corrupt') || error.message.includes('invalid') || error.message.includes('expired')) {
                        try {
                            quarantineAuthFolder(instance.agent_id, 'auto_recovery_invalid');
                        } catch (quarantineError) {
                            console.warn(`⚠️ [AUTO RECOVERY] Erro ao colocar credenciais em quarentena: ${quarantineError.message}`);
                        }
                    }
                } else {
                    // Erro temporário - manter status atual e tentar novamente depois
                    console.log(`⏳ [AUTO RECOVERY] Erro temporário para ${instance.agent_id} - mantendo status atual`);
                }
            }
        }

        console.log('✅ [AUTO RECOVERY] Processo de recuperação concluído');

    } catch (error) {
        console.error('❌ [AUTO RECOVERY] Erro crítico no serviço de recuperação:', error.message);
        console.error('❌ [AUTO RECOVERY] Stack trace:', error.stack);

        // 🆕 Em caso de erro crítico, não parar o serviço
        console.log('🔄 [AUTO RECOVERY] Continuando operação apesar do erro...');
    }
}

// 🛡️ VERIFICAÇÃO DE RATE LIMITING PARA MENSAGENS (ANTISPAM WHATSAPP)
function checkMessageRateLimit(agentId, conversationId) {
    const now = Date.now();

    // Inicializar estrutura se não existe
    if (!messageRateLimit.has(agentId)) {
        messageRateLimit.set(agentId, { count: 0, pending: 0, windowStart: now });
    }

    const agentLimits = messageRateLimit.get(agentId);
    agentLimits.pending = agentLimits.pending || 0;

    // Reset janela por minuto se necessário
    if (now - agentLimits.windowStart >= MINUTE_WINDOW) {
        agentLimits.count = 0;
        agentLimits.windowStart = now;
    }

    // Reservar a vaga no momento em que o envio é aceito. Sem essa reserva,
    // várias requisições simultâneas passariam no teste antes do primeiro envio.
    if (agentLimits.count + agentLimits.pending >= MAX_MESSAGES_PER_MINUTE) {
        const remainingTime = Math.ceil((agentLimits.windowStart + MINUTE_WINDOW - now) / 1000);
        console.warn(`🚫 [MESSAGE RATE LIMIT] Instância ${agentId} excedeu limite por minuto (${agentLimits.count + agentLimits.pending}/${MAX_MESSAGES_PER_MINUTE})`);
        return { allowed: false, reason: `Limite de mensagens por minuto excedido. Aguarde ${remainingTime} segundos.` };
    }

    agentLimits.pending++;
    return { allowed: true, reserved: true };
}

// 📊 Confirmar uma vaga reservada após envio concluído.
function recordMessageSent(agentId, conversationId) {
    const now = Date.now();

    if (!messageRateLimit.has(agentId)) {
        messageRateLimit.set(agentId, { count: 0, pending: 0, windowStart: now });
    }

    const limits = messageRateLimit.get(agentId);
    limits.pending = Math.max(0, limits.pending - 1);
    limits.count++;
}

// Liberar uma reserva quando o envio não chegou a ser iniciado.
function releaseMessageRateLimit(agentId) {
    const limits = messageRateLimit.get(agentId);
    if (limits) {
        limits.pending = Math.max(0, limits.pending - 1);
    }
}

// 🚫 VERIFICAÇÃO DE CONTEÚDO SUSPEITO
function isContentSuspicious(messageText) {
    if (!messageText || typeof messageText !== 'string') {
        return false;
    }

    const text = messageText.toLowerCase();

    // Verificar padrões suspeitos
    for (const pattern of SUSPICIOUS_PATTERNS) {
        if (pattern.test(text)) {
            console.warn(`🚫 [CONTENT FILTER] Conteúdo suspeito detectado: "${pattern}" em "${messageText.substring(0, 50)}..."`);
            return true;
        }
    }

    return false;
}

// 🆕 REMOVIDO: checkRecentActivity - função não utilizada após remoção do health monitor antigo

// Formatação simples de JID para WhatsApp
function formatToWhatsAppJid(phoneNumber, agentId = null) {
    if (!phoneNumber || typeof phoneNumber !== 'string') {
        throw new Error(`Número inválido: ${phoneNumber}`);
    }

    // Se já é JID válido, retornar como está
    if (phoneNumber.includes('@s.whatsapp.net') || phoneNumber.includes('@lid') || phoneNumber.includes('@c.us')) {
        return phoneNumber;
    }

    // Tentar usar mapeamento @lid se disponível
    if (agentId && contactMapping.has(agentId)) {
        const phoneKey = phoneNumber.startsWith('+') ? phoneNumber : `+${phoneNumber}`;
        const mappedLid = contactMapping.get(agentId)?.get(phoneKey);
        if (mappedLid) {
            return mappedLid;
        }
    }

    // Limpeza básica e formatação para Brasil
    const cleanNumber = phoneNumber.replace(/[^\d]/g, '');
    const formattedNumber = cleanNumber.startsWith('55') ? cleanNumber : `55${cleanNumber}`;

    if (formattedNumber.length < 12) {
        throw new Error(`Número muito curto: ${formattedNumber}`);
    }

    return `${formattedNumber}@s.whatsapp.net`;
}

// ✅ 8️⃣ SESSION HEALTH CHECK: Detecção de sessões corrompidas
function validateSessionHealth(agentId) {
    try {
        const client = clients.get(agentId);
        if (!client) return { healthy: true, canGenerateQr: true };

        const authFolder = authFolderFor(agentId);
        const credsPath = path.join(authFolder, 'creds.json');

        // Verifica se arquivo de credenciais existe
        if (!fs.existsSync(credsPath)) {
            // ❌ Credenciais não existem = sessão nunca foi conectada
            // ✅ PODE gerar QR normalmente
            return { healthy: true, canGenerateQr: true, reason: 'Sessão nunca conectada' };
        }

        // Tenta ler e validar JSON
        try {
            const credsData = fs.readFileSync(credsPath, 'utf8');
            const creds = JSON.parse(credsData);

            // Validações básicas de integridade
            if (!creds.me || !creds.account) {
                return { healthy: false, canGenerateQr: false, reason: 'Credenciais incompletas/corrompidas' };
            }

            // 🆕 REMOVIDO: Verificação de 30 dias (muito restritiva)
            // Sessões antigas ainda podem ser válidas se o WhatsApp aceitar
            // Deixar o Baileys decidir se a sessão está expirada ou não

        } catch (parseError) {
            // ❌ JSON corrompido = credenciais realmente corrompidas
            // ❌ NÃO pode gerar QR, precisa limpar e recriar
            return { healthy: false, canGenerateQr: false, reason: 'Credenciais corrompidas - JSON inválido' };
        }

        // ✅ Credenciais existem e são válidas
        return { healthy: true, canGenerateQr: true };

    } catch (error) {
        return { healthy: false, canGenerateQr: false, reason: `Erro na validação: ${error.message}` };
    }
}

// 🆕 REMOVIDO: Health Monitor antigo baseado em WebSocket
// Substituído pelo sistema de monitoramento do Baileys (baileysStatusMonitor)
// O novo sistema verifica status real do Baileys e não interfere com conexões ativas

// 🧹 LIMPEZA PERIÓDICA DO MAPA DE DESCONEXÕES PROCESSADAS
setInterval(() => {
    try {
        const now = Date.now();
        const CLEANUP_THRESHOLD = 10 * 60 * 1000; // 10 minutos

        for (const [agentId, timestamp] of disconnectionProcessing.entries()) {
            if (now - timestamp > CLEANUP_THRESHOLD) {
                disconnectionProcessing.delete(agentId);
            }
        }
    } catch (error) {
        console.warn('⚠️ Erro na limpeza do mapa de desconexões:', error.message);
    }
}, 10 * 60 * 1000); // Executar a cada 10 minutos

// 📨 GESTÃO DE FILA DE MENSAGENS PENDENTES
function addPendingMessage(agentId, payload, response) {
    if (!pendingMessages.has(agentId)) {
        pendingMessages.set(agentId, []);
    }

    const queue = pendingMessages.get(agentId);

    // Limitar fila a no máximo 10 mensagens por agente
    if (queue.length >= 10) {
        console.warn(`⚠️ [${agentId}] Fila de mensagens pendentes cheia, descartando mensagem antiga`);
        queue.shift(); // Remove a mais antiga
    }

    queue.push({
        payload,
        response,
        timestamp: Date.now()
    });

    console.log(`📋 [${agentId}] Mensagem adicionada à fila pendente (${queue.length} mensagens)`);
}

async function processPendingMessages(agentId) {
    const queue = pendingMessages.get(agentId);
    if (!queue || queue.length === 0) {
        return;
    }

    if (!await ensureBaileysProvider(agentId)) {
        clearPendingMessages(agentId);
        return;
    }

    console.log(`📨 [${agentId}] Processando ${queue.length} mensagens pendentes...`);

    const client = clients.get(agentId);
    if (!client || !client.isConnected) {
        console.warn(`⚠️ [${agentId}] Cliente não disponível, mantendo mensagens na fila`);
        return;
    }

    while (queue.length > 0) {
        const pending = queue.shift();

        try {
            // Reutiliza todas as validações, fila e limite do fluxo normal.
            // A fila só recebe falhas ocorridas antes de iniciar o envio.
            await sendWhatsappResponse(pending.payload, pending.response);
            if (queue.length > 0) {
                await new Promise(resolve => setTimeout(resolve, 6500));
            }
        } catch (error) {
            console.error(`❌ [${agentId}] Erro ao enviar mensagem pendente:`, error.message);
            queue.unshift(pending);
            break;
        }
    }

    console.log(`✅ [${agentId}] Todas as mensagens pendentes foram processadas`);
}

function clearPendingMessages(agentId) {
    const queue = pendingMessages.get(agentId);
    if (queue && queue.length > 0) {
        console.log(`🧹 [${agentId}] Limpando ${queue.length} mensagens pendentes`);
        pendingMessages.delete(agentId);
    }
}

// 🛠️ FUNÇÃO AUXILIAR PARA MARCAR INSTÂNCIA COMO CORROMPIDA
async function markAsCorrupted(agentId, payload, response) {
    console.error(`🚨 [${agentId}] Marcando instância como corrompida`);

    // Limpar estado completamente
    const client = clients.get(agentId);
    if (client) {
        client.isConnected = false;
        client._connectionEstablished = false;
    }

    clients.delete(agentId);
    statuses.delete(agentId);
    processedMessages.delete(agentId); // Limpar cache de mensagens

    if (typeof global !== 'undefined' && global.activeSessions) {
        global.activeSessions.delete(agentId);
    }
    if (typeof global !== 'undefined' && global.keysCache) {
        global.keysCache.delete(agentId);
    }

    // Resetar status no banco
    try {
        await db.query(
            `UPDATE whatsapp_instances
             SET status = 'corrupted',
                 error_message = 'Problema de envio detectado - reconexão manual necessária',
                 updated_at = NOW()
             WHERE agent_id = ? AND provider = 'baileys'`,
            [agentId]
        );
        console.log(`💾 [${agentId}] Instância marcada como corrompida no banco`);
    } catch (dbError) {
        console.error(`❌ [${agentId}] Erro ao marcar como corrompida:`, dbError.message);
    }

    // Notificar frontend
    io.to(agentId).emit('status', {
        agent_id: agentId,
        status: 'corrupted',
        message: 'Problema de envio detectado. Clique em "Reconectar (Corrompida)" para gerar um novo QR code.',
        requires_new_qr: true,
        timestamp: Date.now()
    });

    // 📋 ADICIONAR À FILA DE MENSAGENS PENDENTES
    addPendingMessage(agentId, payload, response);

    console.log(`📋 [${agentId}] Mensagem adicionada à fila pendente`);
}

// ✅ Tornar funções globais disponíveis para BaileysClient
if (typeof global !== 'undefined') {
    global.keysCache = keysCache;
    global.processPendingMessages = processPendingMessages;
    global.clearPendingMessages = clearPendingMessages;
    global.updateContactMapping = updateContactMapping;
}

// ✅ 7️⃣ SESSION LOCK: Prevenir sessões duplicadas
const activeSessions = new Set(); // agentId -> true
if (typeof global !== 'undefined') {
    global.activeSessions = activeSessions;
}

/**
 * Limpa completamente um cliente do servidor
 * Usado quando logout é solicitado ou sessão deve ser totalmente removida
 * 🆕 IMPORTANTE: Desconecta o Baileys ANTES de limpar recursos para evitar múltiplas conexões
 */
async function cleanupClient(agentId, reason = 'manual') {
    console.log(`🧹 Limpando cliente ${agentId} - razão: ${reason}`);

    // 🆕 1. PRIMEIRO: Desconectar o Baileys ANTES de remover do Map
    const client = clients.get(agentId);
    if (client) {
        try {
            // Fazer logout completo (deleta arquivos de autenticação) ou disconnect
            if (reason === 'logout') {
                await client.logout();
                console.log(`✅ [${agentId}] Logout realizado antes da limpeza`);
            } else {
                // Para outras razões, usar disconnect (não deleta arquivos)
                await client.disconnect();
                console.log(`✅ [${agentId}] Cliente desconectado antes da limpeza`);
            }
        } catch (error) {
            console.warn(`⚠️ [${agentId}] Erro ao desconectar cliente antes da limpeza:`, error.message);
            // Tentar disconnect como fallback
            try {
                if (client.disconnect && typeof client.disconnect === 'function') {
                    await client.disconnect();
                }
            } catch (disconnectError) {
                console.warn(`⚠️ [${agentId}] Erro no disconnect fallback:`, disconnectError.message);
            }
        }

        // Agora remover do Map APÓS desconexão
        clients.delete(agentId);
    }

    // Remove status
    if (statuses.has(agentId)) {
        statuses.delete(agentId);
    }

    // 🆕 Remover do monitoramento do Baileys
    if (baileysStatusMonitor.has(agentId)) {
        baileysStatusMonitor.delete(agentId);
        console.log(`🗑️ [${agentId}] Removido do monitoramento do Baileys`);
    }

    // ✅ Remover keys do cache em memória
    if (keysCache.has(agentId)) {
        keysCache.delete(agentId);
    }

    // ✅ 7️⃣ Remover session lock
    if (typeof global !== 'undefined' && global.activeSessions) {
        global.activeSessions.delete(agentId);
    }

    // 📋 LIMPAR MENSAGENS PENDENTES
    if (['logout', 'trial_expired', 'provider_switch'].includes(reason) &&
        typeof global !== 'undefined' && global.clearPendingMessages) {
        global.clearPendingMessages(agentId);
    }

    // ✅ DELETAR DIRETÓRIO AUTH QUANDO FOR LOGOUT
    if (reason === 'logout') {
        try {
            // Usar caminho absoluto para garantir que está correto
            const authDir = authFolderFor(agentId);
            console.log(`🗑️ [${agentId}] Tentando deletar diretório Auth: ${authDir}`);

            if (fs.existsSync(authDir)) {
                console.log(`📁 [${agentId}] Diretório encontrado, iniciando deleção...`);

                // Usar rmSync com recursive e force para deletar tudo de uma vez
                fs.rmSync(authDir, { recursive: true, force: true });
                console.log(`✅ [${agentId}] Diretório Auth deletado com sucesso!`);

                // Verificar se realmente foi deletado
                if (!fs.existsSync(authDir)) {
                    console.log(`✅ [${agentId}] Confirmação: Diretório não existe mais`);
                } else {
                    console.warn(`⚠️ [${agentId}] ATENÇÃO: Diretório ainda existe após tentativa de deleção!`);
                }
            } else {
                console.log(`📁 [${agentId}] Diretório Auth não encontrado: ${authDir}`);
            }

            // 🆕 Deletar pasta de backups se existir (sistema de backups removido)
            const backupDir = authBackupFolderFor(agentId);
            if (fs.existsSync(backupDir)) {
                try {
                    fs.rmSync(backupDir, { recursive: true, force: true });
                    console.log(`✅ [${agentId}] Pasta de backups deletada`);
                } catch (backupError) {
                    console.warn(`⚠️ [${agentId}] Erro ao deletar pasta de backups:`, backupError.message);
                }
            }
        } catch (error) {
            console.error(`❌ [${agentId}] Erro ao deletar diretório Auth:`, error.message);
            console.error(`❌ [${agentId}] Stack trace:`, error.stack);
        }
    }

    // Notifica desconexão com mensagem apropriada
    const getMessage = () => {
        switch (reason) {
            case 'logout':
                return 'Logout realizado. Gere um novo QR code para reconectar.';
            case 'fatal_error':
                return 'Sessão inválida/corrompida. Gere um novo QR Code.';
            case 'error':
                return 'Erro crítico detectado. Gere um novo QR Code.';
            case 'trial_expired':
                return 'Instância de teste expirada após 48 horas. Para continuar, assine um plano.';
            default:
                return 'Cliente desconectado.';
        }
    };

    const eventData = {
        agent_id: agentId,
        status: reason === 'trial_expired' ? 'expired' : 'disconnected',
        requires_new_qr: ['logout', 'error', 'fatal_error', 'trial_expired'].includes(reason),
        message: getMessage(),
        timestamp: Date.now()
    };

    io.to(agentId).emit('status', eventData);

    console.log(`🧹 Cliente ${agentId} completamente limpo`);
}

/**
 * 🆕 Baixa mídia de uma mensagem do WhatsApp
 * @param {Object} message - Objeto da mensagem do Baileys
 * @param {Object} socket - Socket do Baileys
 * @param {string} agentId - ID do agente
 * @returns {Promise<string|null>} - Caminho do arquivo baixado ou null se erro
 */
async function downloadMediaFromMessage(message, socket, agentId) {
    try {
        if (!socket || !message?.message) {
            return null;
        }

        const msgInfo = message.message;
        let mediaMessage = null;
        let fileExtension = '';

        // Identificar tipo de mídia
        if (msgInfo.imageMessage) {
            mediaMessage = msgInfo.imageMessage;
            const mimeType = mediaMessage.mimetype || 'image/jpeg';
            fileExtension = mimeType.split('/')[1] || 'jpg';
        } else if (msgInfo.videoMessage) {
            mediaMessage = msgInfo.videoMessage;
            const mimeType = mediaMessage.mimetype || 'video/mp4';
            fileExtension = mimeType.split('/')[1] || 'mp4';
        } else if (msgInfo.audioMessage) {
            mediaMessage = msgInfo.audioMessage;
            const mimeType = mediaMessage.mimetype || 'audio/ogg';
            // WhatsApp geralmente usa ogg para áudio
            fileExtension = mimeType.includes('ogg') ? 'ogg' : mimeType.split('/')[1] || 'ogg';
        } else if (msgInfo.documentMessage) {
            mediaMessage = msgInfo.documentMessage;
            const fileName = mediaMessage.fileName || 'document';
            fileExtension = fileName.split('.').pop() || 'bin';
        } else {
            return null; // Não é mídia
        }

        // Criar diretório para mídia se não existir
        const mediaDir = path.resolve(__dirname, '../../../storage/app/whatsapp_media');
        await mkdir(mediaDir, { recursive: true, mode: 0o700 });
        try {
            await fs.promises.chmod(mediaDir, 0o700);
        } catch {
            // Mantém o fluxo quando o filesystem não suporta chmod.
        }

        // Gerar nome único para o arquivo
        const safeAgentId = String(agentId).replace(/[^A-Za-z0-9_-]/g, '_').slice(0, 80);
        const messageId = String(message.key?.id || Date.now()).replace(/[^A-Za-z0-9_-]/g, '_').slice(0, 120);
        fileExtension = String(fileExtension).toLowerCase().replace(/[^a-z0-9]/g, '').slice(0, 10) || 'bin';
        const timestamp = Date.now();
        const filename = `${safeAgentId}_${timestamp}_${messageId}.${fileExtension}`;
        const filePath = path.join(mediaDir, filename);

        // Baixar mídia usando Baileys
        const buffer = await downloadMediaMessage(
            message,
            'buffer',
            {},
            {
                logger: { level: 'silent' },
                reuploadRequest: socket.updateMediaMessage
            }
        );

        // Salvar arquivo
        await writeFile(filePath, buffer, { mode: 0o600 });

        console.log(`📥 [${agentId}] Mídia baixada: ${filename} (${(buffer.length / 1024).toFixed(2)} KB)`);

        // Retornar caminho relativo para o Laravel
        return `whatsapp_media/${filename}`;
    } catch (error) {
        console.error(`❌ [${agentId}] Erro ao baixar mídia:`, error.message);
        return null;
    }
}

/**
 * Processa mensagens recebidas via Baileys - versão simplificada
 */
async function handleIncomingMessageBaileys(msg, agentId) {
    try {
        if (!await ensureBaileysProvider(agentId)) return;

        console.log(`📥 [${agentId}] handleIncomingMessageBaileys chamado - tipo: ${msg.type}, mensagens: ${msg.messages?.length || 0}`);

        // Validações básicas
        if (!msg.messages?.length || msg.type !== 'notify') {
            console.log(`🚫 [${agentId}] Mensagem ignorada - sem mensagens ou tipo inválido`);
            return;
        }

        // Controle global de duplicação - verificar se já processamos alguma destas mensagens
        if (!processedMessagesGlobal.has(agentId)) {
            processedMessagesGlobal.set(agentId, new Set());
        }

        const globalCache = processedMessagesGlobal.get(agentId);
        const newMessages = msg.messages.filter(message => {
            const messageId = message.key?.id;
            if (!messageId) return false;

            if (globalCache.has(messageId)) {
                console.log(`🔄 [GLOBAL] Mensagem já processada globalmente: ${messageId}`);
                return false;
            }

            return true;
        });

        if (!newMessages.length) {
            console.log(`🚫 [${agentId}] Todas as mensagens já foram processadas globalmente`);
            return;
        }

        // Substituir mensagens e adicionar ao cache global
        msg.messages = newMessages;
        newMessages.forEach(message => {
            const messageId = message.key?.id;
            if (messageId) {
                globalCache.add(messageId);
                // Manter últimas 200 mensagens por agent
                if (globalCache.size > 200) {
                    const firstId = globalCache.values().next().value;
                    globalCache.delete(firstId);
                }
            }
        });

        // Verificar se agente está habilitado e conectado
        if (!(await isAgentEnabled(agentId))) {
            return;
        }

        const client = clients.get(agentId);
        // ✅ Verificar se cliente está conectado E se a conexão está estabelecida
        // O Baileys gerencia o WebSocket internamente, então confiamos apenas nas flags de conexão
        if (!client?.isConnected || !client._connectionEstablished || !client.phoneNumber) {
            console.log(`🚫 [${agentId}] Ignorando mensagem - sessão não conectada (isConnected: ${client?.isConnected}, _connectionEstablished: ${client?._connectionEstablished}, phoneNumber: ${client?.phoneNumber})`);
            return;
        }

        // 🆕 VERIFICAÇÃO ADICIONAL: Verificar se WebSocket está ativo
        const wsReadyState = client.socket?.ws?.readyState;
        if (wsReadyState === 3) { // CLOSED
            console.log(`🚫 [${agentId}] WebSocket fechado - ignorando mensagem`);
            return;
        }

        // 🆕 VERIFICAÇÃO ADICIONAL: Verificar se credenciais estão válidas
        const hasValidCreds = !!(client.socket?.authState?.state?.creds?.me || client.phoneNumber || client.isConnected);
        if (!hasValidCreds) {
            console.log(`🚫 [${agentId}] Credenciais não inicializadas - ignorando mensagem`);
            return;
        }

        const ownNumber = client.phoneNumber;

        // Filtrar mensagens válidas (últimas 10 min, não duplicadas)
        const now = Date.now();
        const validMessages = [];

        for (const message of msg.messages) {
            // Verificar timestamp
            const messageTime = (message.messageTimestamp || message.timestamp || 0) * 1000;
            const ageInMinutes = (now - messageTime) / (1000 * 60);

            if (ageInMinutes > 10 || messageTime > now + (1000 * 60 * 5)) {
                continue; // Mensagem muito antiga ou futura
            }

            // Controle rigoroso de duplicação
            const messageId = message.key?.id;
            if (!messageId) continue; // Sem ID, pular

            if (!processedMessages.has(agentId)) {
                processedMessages.set(agentId, new Set());
            }

            const agentCache = processedMessages.get(agentId);
            if (agentCache.has(messageId)) {
                console.log(`🔄 [${agentId}] Mensagem duplicada ignorada: ${messageId}`);
                continue;
            }

            // Adicionar ao cache
            agentCache.add(messageId);
            if (agentCache.size > 500) {
                const firstId = agentCache.values().next().value;
                agentCache.delete(firstId);
            }

            validMessages.push(message);
        }

        if (!validMessages.length) {
            return;
        }

        // Processar cada mensagem (máximo uma por vez para evitar sobrecarga)
        let processedCount = 0;
        for (const message of validMessages) {
            if (processedCount >= 3) { // Limitar processamento a 3 mensagens por lote
                console.log(`⚠️ [${agentId}] Lote limitado a 3 mensagens processadas`);
                break;
            }

            try {
                if (!message?.message) continue;

                const msgInfo = message.message;
                const conversation = msgInfo.conversation || msgInfo.extendedTextMessage?.text || '';

                // Verificar se tem conteúdo
                if (!conversation && !msgInfo.imageMessage && !msgInfo.audioMessage && !msgInfo.documentMessage) {
                    continue;
                }

                // Extrair informações básicas
                const isFromMe = message.key.fromMe;

                // remoteJidAlt é sempre referente ao usuário
                const remoteJid = message.key.remoteJid ?? null;  // @lid do usuario
                const remoteJidAlt = message.key.remoteJidAlt ?? null; // @s.whatsapp.net do usuario

                // Ignorar mensagens do bot (filtro básico)
                if (conversation.includes(BOT_RESPONSE_MARKER)) {
                    console.log(`🚫 [${agentId}] Mensagem do bot ignorada`);
                    continue;
                }

                // Ignorar mensagens de status
                if (remoteJid === 'status@broadcast') {
                    console.log(`🚫 [${agentId}] Mensagem de status ignorada`);
                    continue;
                }

                processedCount++;

                // Determinar participantes
                let from, to, contactLid = null;

                // Extrair @lid do remetente (remoteJidAlt) ou de chatLid
                if (remoteJid && String(remoteJid).includes('@lid')) {
                    contactLid = remoteJid;
                } else if (message.key?.chatLid) {
                    contactLid = message.key.chatLid;
                } else if (remoteJidAlt && String(remoteJidAlt).includes('@s.whatsapp.net')) {
                    // Se não tiver @lid, usar o próprio remoteJidAlt como fallback
                    contactLid = remoteJid;
                }

                if (isFromMe) {
                    // Mensagem enviada pelo agente
                    from = remoteJidAlt;
                    // Para mensagens saídas, usar remoteJidAlt (usuário destinatário)
                    to = remoteJidAlt || remoteJid;
                } else {
                    // Mensagem recebida do usuário
                    // Para mensagens de entrada, usar remoteJidAlt (usuário remetente)
                    from = remoteJidAlt || remoteJid;
                    to = remoteJidAlt;
                }

                // 🆕 Extrair pushName (nome do usuário) quando fromMe: false
                const pushName = message.pushName ? message.pushName : null;

                // Extrair messageId do WhatsApp (único para cada mensagem)
                const whatsappMessageId = message.key?.id || null;

                // Criar payload simplificado
                const payload = {
                    from: from, // Quem enviou
                    to: to,     // Quem recebeu
                    contact_lid: contactLid, // @lid ou @s.whatsapp.net do contato (se disponível)
                    agent_id: agentId,
                    body: conversation,
                    messageType: 'text',
                    direction: isFromMe ? 'output' : 'input',
                    isFromMe: isFromMe,
                    userName: pushName, // 🆕 Nome do usuário (apenas quando fromMe: false)
                    whatsapp_message_id: whatsappMessageId // 🆕 ID único da mensagem do WhatsApp
                };

                // Adicionar tipo de mídia se existir e baixar arquivo
                if (msgInfo.imageMessage) {
                    payload.messageType = 'image';
                    payload.mimetype = msgInfo.imageMessage.mimetype;
                    payload.caption = msgInfo.imageMessage.caption || '';

                    // 🆕 Baixar mídia
                    const client = clients.get(agentId);
                    if (client?.socket) {
                        const mediaPath = await downloadMediaFromMessage(message, client.socket, agentId);
                        if (mediaPath) {
                            payload.mediaPath = mediaPath;
                            payload.body = payload.caption || '[Imagem]';
                        }
                    }
                } else if (msgInfo.videoMessage) {
                    payload.messageType = 'video';
                    payload.mimetype = msgInfo.videoMessage.mimetype;
                    payload.caption = msgInfo.videoMessage.caption || '';

                    // 🆕 Baixar mídia
                    const client = clients.get(agentId);
                    if (client?.socket) {
                        const mediaPath = await downloadMediaFromMessage(message, client.socket, agentId);
                        if (mediaPath) {
                            payload.mediaPath = mediaPath;
                            payload.body = payload.caption || '[Vídeo]';
                        }
                    }
                } else if (msgInfo.audioMessage) {
                    payload.messageType = 'audio';
                    payload.mimetype = msgInfo.audioMessage.mimetype;

                    // 🆕 Baixar mídia
                    const client = clients.get(agentId);
                    if (client?.socket) {
                        const mediaPath = await downloadMediaFromMessage(message, client.socket, agentId);
                        if (mediaPath) {
                            payload.mediaPath = mediaPath;
                            payload.body = '[Áudio]';
                        }
                    }
                } else if (msgInfo.documentMessage) {
                    payload.messageType = 'document';
                    payload.mimetype = msgInfo.documentMessage.mimetype;
                    payload.filename = msgInfo.documentMessage.fileName;

                    // 🆕 Baixar mídia
                    const client = clients.get(agentId);
                    if (client?.socket) {
                        const mediaPath = await downloadMediaFromMessage(message, client.socket, agentId);
                        if (mediaPath) {
                            payload.mediaPath = mediaPath;
                            payload.body = payload.filename || '[Documento]';
                        }
                    }
                }

                // 🆕 CENTRALIZAR VALIDAÇÃO DE AGENTE PESSOAL AQUI
                // A função isAgentPersonal agora decide se deve processar baseado no payload
                const personalCheck = await isAgentPersonal(agentId, payload);

                if (personalCheck.isPersonal && !personalCheck.shouldProcess) {
                    console.log(`🚫 [${agentId}] Mensagem pessoal não processada: ${personalCheck.reason}`);
                    continue;
                }

                // Enviar para processamento
                await handleIncomingMessage(payload, sendWhatsappResponse);

                // Notificar via socket
                io.to(agentId).emit('message', {
                    agent_id: agentId,
                    from: payload.from,
                    to: payload.to,
                    contact_lid: payload.contact_lid,
                    direction: payload.direction,
                    body: payload.body,
                    message: payload.body,
                    messageType: payload.messageType,
                    mediaPath: payload.mediaPath,
                    userName: payload.userName,
                    timestamp: Date.now()
                });

                // 🆕 ATUALIZAR MAPEAMENTO DE CONTATO se temos phone e contact_lid
                // Apenas mapear quando `payload.from` for um PN (ex: xxx@s.whatsapp.net)
                if (payload.from && payload.contact_lid && !isFromMe && payload.from.includes('@s.whatsapp.net')) {
                    // Limpar :Number do telefone antes de mapear
                    const cleanPhone = payload.from.split(':')[0];
                    updateContactMapping(agentId, cleanPhone, payload.contact_lid);
                }

                if (personalCheck.isPersonal && isFromMe) {
                    console.log(`[${agentId}] Modo pessoal: Mensagem processada`);
                }

            } catch (messageError) {
                console.error(`[${agentId}] Erro ao processar mensagem individual:`, messageError.message);
                // Continua processando outras mensagens
            }
        }

    } catch (error) {
        console.error(`[${agentId}] Erro ao processar evento de mensagens:`, error.message);
    }
}

/**
 * Inicializa sessão WhatsApp - Servidor atua apenas como ponte
 * Toda lógica de autenticação, sessão e criptografia fica no BaileysClient
 */
async function initializeSession(socket, config, options = {}) {
    const userId = config.userId;
    const agentId = config.agentId;

    if (!await ensureBaileysProvider(agentId, userId, socket)) {
        return { success: false, provider_conflict: true };
    }

    // ✅ MODO DE SESSÃO:
    // - freshSession=true (padrão; ações explícitas do usuário): limpeza completa + novo QR
    // - freshSession=false (fluxos internos sem ação do usuário): PRESERVA credenciais
    //   válidas e reconecta reutilizando-as — evita deslogar a instância à toa
    const freshSession = options.freshSession !== false;

    console.log(`🚀 [${agentId}] Iniciando sessão (modo: ${freshSession ? 'sessão nova' : 'recuperação preservando credenciais'})`);

    // 1. Desconectar e limpar cliente existente em memória
    if (clients.has(agentId)) {
        console.log(`🔄 [${agentId}] Desconectando e removendo cliente existente`);
        const oldClient = clients.get(agentId);
        if (oldClient) {
            try {
                if (freshSession) {
                    // Fazer logout completo (deleta arquivos de autenticação)
                    await oldClient.logout();
                    console.log(`✅ [${agentId}] Cliente antigo desconectado e logout realizado`);
                } else {
                    // ✅ Recuperação: apenas desconecta — arquivos de autenticação ficam intactos
                    await oldClient.disconnect();
                    console.log(`✅ [${agentId}] Cliente antigo desconectado (credenciais preservadas)`);
                }
            } catch (error) {
                console.warn(`⚠️ [${agentId}] Erro ao fazer logout do cliente antigo:`, error.message);
                // Tentar desconectar mesmo assim
                try {
                    await oldClient.disconnect();
                } catch (disconnectError) {
                    console.warn(`⚠️ [${agentId}] Erro ao desconectar cliente antigo:`, disconnectError.message);
                }
            }
        }
        clients.delete(agentId);
        console.log(`🗑️ [${agentId}] Cliente antigo removido do Map`);
    }

    // 2. Deletar arquivos de autenticação diretamente (garantia extra) — só em sessão nova
    const authFolder = authFolderFor(agentId);
    if (freshSession && fs.existsSync(authFolder)) {
        try {
            console.log(`🗑️ [${agentId}] Deletando pasta de autenticação: ${authFolder}`);
            fs.rmSync(authFolder, { recursive: true, force: true });
            console.log(`✅ [${agentId}] Pasta de autenticação deletada com sucesso`);
        } catch (fsError) {
            console.warn(`⚠️ [${agentId}] Erro ao deletar pasta de autenticação:`, fsError.message);
        }
    } else if (!freshSession) {
        console.log(`🔐 [${agentId}] Pasta de autenticação preservada (modo recuperação)`);
    } else {
        console.log(`ℹ️ [${agentId}] Pasta de autenticação não encontrada (já estava limpa)`);
    }

    // 🛡️ 2.1. Preservar quarentenas para auditoria/recuperação. O diretório
    // de backup não participa da autenticação ativa e só deve ser removido
    // por logout explícito ou rotina de retenção autorizada.
    const backupFolder = authBackupFolderFor(agentId);
    if (fs.existsSync(backupFolder)) {
        console.log(`🛡️ [${agentId}] Quarentena preservada durante nova sessão: ${backupFolder}`);
    }

    // 3. Limpar recursos relacionados
    if (typeof global !== 'undefined' && global.keysCache) {
        global.keysCache.delete(agentId);
    }
    if (typeof global !== 'undefined' && global.activeSessions) {
        global.activeSessions.delete(agentId);
    }
    // ✅ Em recuperação a fila pendente é mantida — é descarregada no onConnected
    if (freshSession && typeof global !== 'undefined' && global.clearPendingMessages) {
        global.clearPendingMessages(agentId);
    }

    // 4. Limpar status e caches
    statuses.delete(agentId);
    processedMessages.delete(agentId);
    if (processedMessagesGlobal.has(agentId)) {
        processedMessagesGlobal.delete(agentId);
    }

    // 5. Atualizar banco para 'disconnected'/limpar QR — só em sessão nova;
    //    em recuperação o próprio fluxo de conexão atualiza o status real
    if (!freshSession) {
        console.log(`✅ [${agentId}] Modo recuperação: status do banco preservado até a conexão confirmar`);
        try {
            await db.query(
                `UPDATE whatsapp_instances SET status = 'connecting', updated_at = NOW() WHERE agent_id = ? AND provider = 'baileys' AND status = 'disconnected'`,
                [agentId]
            );
        } catch (dbError) {
            console.warn(`⚠️ [${agentId}] Erro ao marcar connecting:`, dbError.message);
        }
    } else {
        try {
            await db.query(
            `UPDATE whatsapp_instances
             SET status = 'disconnected',
                 qr_code = NULL,
                 phone_number = NULL,
                 connected_at = NULL,
                 error_message = 'Sessão reiniciada - novo QR Code será gerado',
                 updated_at = NOW()
             WHERE agent_id = ? AND provider = 'baileys'`,
                [agentId]
            );
            console.log(`✅ [${agentId}] Status atualizado no banco de dados`);
        } catch (dbError) {
            console.warn(`⚠️ [${agentId}] Erro ao atualizar status no banco:`, dbError.message);
        }
    }

    console.log(`✅ [${agentId}] Limpeza concluída - iniciando ${freshSession ? 'nova sessão' : 'recuperação de sessão'}`);

    // ✅ Adicionar socket à sala (pode ser null em inicializações internas: Health Monitor, /refresh-qr HTTP)
    if (socket) {
        socket.join(agentId);
        socket.agentId = agentId;
    }

    // Configuração simplificada - BaileysClient gerencia tudo
    const baileysConfig = {
        userId: userId,
        agentId: agentId,
        connection: db,

        // Callbacks para comunicação com frontend via Socket.IO
        onQr: (qr, agentId) => {
            console.log(`🎯 QR gerado para ${agentId}`);

            // ✅ Validar QR antes de processar
            if (!qr || typeof qr !== 'string' || qr.trim() === '') {
                console.error(`❌ [${agentId}] QR code inválido recebido do Baileys:`, qr);
                io.to(agentId).emit('status', {
                    agent_id: agentId,
                    status: 'error',
                    error: 'QR code inválido recebido. Tentando gerar novo...',
                    requires_new_qr: true
                });
                return;
            }

            // ✅ Validar formato do QR (deve começar com algo como "1@..." ou similar)
            if (qr.length < 10) {
                console.error(`❌ [${agentId}] QR code muito curto (${qr.length} caracteres):`, qr.substring(0, 50));
                io.to(agentId).emit('status', {
                    agent_id: agentId,
                    status: 'error',
                    error: 'QR code inválido. Tentando gerar novo...',
                    requires_new_qr: true
                });
                return;
            }

            // ✅ 7️⃣ STATUS PADRONIZADO: 'qr'
            statuses.set(agentId, 'qr');

            // ✅ Opções otimizadas para geração do QR code
            const qrOptions = {
                errorCorrectionLevel: 'M', // Nível de correção de erro (L, M, Q, H)
                type: 'image/png',
                quality: 0.92,
                margin: 2, // Margem ao redor do QR code
                color: {
                    dark: '#000000', // Cor escura (QR code)
                    light: '#FFFFFF' // Cor clara (fundo)
                },
                width: 512 // Tamanho da imagem
            };

            qrcode.toDataURL(qr, qrOptions, async (err, dataUrl) => {
                if (err) {
                    console.error(`❌ [${agentId}] Erro ao converter QR code para imagem:`, err.message);
                    console.error(`❌ [${agentId}] Stack trace:`, err.stack);
                    io.to(agentId).emit('status', {
                        agent_id: agentId,
                        status: 'error',
                        error: `Erro ao gerar imagem do QR code: ${err.message}`,
                        requires_new_qr: true
                    });
                    return;
                }

                if (!dataUrl || typeof dataUrl !== 'string') {
                    console.error(`❌ [${agentId}] Data URL inválido retornado:`, typeof dataUrl);
                    io.to(agentId).emit('status', {
                        agent_id: agentId,
                        status: 'error',
                        error: 'Erro ao gerar imagem do QR code',
                        requires_new_qr: true
                    });
                    return;
                }

                // ✅ SIMPLIFICADO: Extrair apenas a string base64 (sem prefixo data:image)
                // Formato: data:image/png;base64,<base64_string>
                const base64String = dataUrl.includes(',') ? dataUrl.split(',')[1] : dataUrl;

                // Validar que base64String é válido (não vazio e não "Invalid value.")
                if (!base64String || base64String === 'Invalid value.' || base64String.trim() === '' || base64String.length < 100) {
                    console.warn(`⚠️ [${agentId}] QR code base64 inválido ou muito curto (${base64String?.length || 0} caracteres)`);
                    io.to(agentId).emit('status', {
                        agent_id: agentId,
                        status: 'error',
                        error: 'QR code gerado é inválido. Tentando gerar novo...',
                        requires_new_qr: true
                    });
                    return;
                }

                console.log(`📤 [${agentId}] QR code gerado com sucesso (${base64String.length} chars), enviando via WebSocket`);

                // ✅ Salvar como JSON válido no banco (coluna é JSON no MySQL)
                try {
                    await db.query(
                        `UPDATE whatsapp_instances
                         SET qr_code = ?,
                             status = 'connecting',
                             updated_at = NOW()
                         WHERE agent_id = ? AND provider = 'baileys'`,
                        [JSON.stringify(base64String), agentId]
                    );
                    console.log(`✅ [${agentId}] QR code salvo no banco (JSON)`);
                } catch (dbError) {
                    console.warn(`⚠️ [${agentId}] Erro ao salvar QR no banco:`, dbError.message);
                }

                // ✅ Enviar data URL completa para o frontend (pronta para usar em <img src>)
                io.to(agentId).emit('qrcode', { agent_id: agentId, url: dataUrl });
                io.to(agentId).emit('status', {
                    agent_id: agentId,
                    status: 'qr', // ✅ Status padronizado
                    qrCode: dataUrl // Data URL completa para o frontend
                });

                console.log(`✅ [${agentId}] QR code enviado para room ${agentId}`);
            });
        },

        /**
         * CALLBACK: onConnected
         * Chamado quando o WhatsApp conecta com sucesso
         * Atualiza status e notifica frontend
         */
        onConnected: async (agentId, phoneNumber, instanceLid) => {
            console.log(`✅ WhatsApp conectado ${agentId}: ${phoneNumber} (LID: ${instanceLid})`);
            // ✅ 7️⃣ STATUS PADRONIZADO: 'connected' (não 'ready')
            statuses.set(agentId, 'connected');

            // 🆕 ARMAZENAR @LID DA INSTÂNCIA PARA VALIDAÇÃO DE SELF-MESSAGES
            if (instanceLid && clients.has(agentId)) {
                clients.get(agentId).instanceLid = instanceLid;
            }

            // 🆕 ATUALIZAR MONITORAMENTO DO BAILEYS (independente do WebSocket)
            baileysStatusMonitor.set(agentId, {
                lastHeartbeat: Date.now(),
                realStatus: 'connected',
                lastUpdate: Date.now(),
                phoneNumber: phoneNumber,
                instanceLid: instanceLid
            });

            // 🆕 ATUALIZAR BANCO DE DADOS BASEADO NO STATUS REAL DO BAILEYS
            try {
                await db.query(
                    `UPDATE whatsapp_instances
                     SET status = 'connected',
                         phone_number = ?,
                         connected_at = NOW(),
                         error_message = NULL,
                         updated_at = NOW()
                     WHERE agent_id = ? AND provider = 'baileys'`,
                    [phoneNumber, agentId]
                );
                console.log(`✅ [${agentId}] Status atualizado no banco baseado no Baileys: connected`);
            } catch (dbError) {
                console.warn(`⚠️ [${agentId}] Erro ao atualizar banco com status do Baileys:`, dbError.message);
            }

            const statusData = {
                agent_id: agentId,
                status: 'connected', // ✅ Status padronizado
                phone_number: phoneNumber,
                instance_lid: instanceLid, // 🆕 Adicionar @lid da instância
            };

            io.to(agentId).emit('status', statusData);
        },

        /**
         * 🆕 CALLBACK: onBaileysStatusChange
         * Chamado quando o status do Baileys muda (independente do WebSocket)
         * Atualiza banco de dados diretamente baseado nos eventos do Baileys
         */
        onBaileysStatusChange: async (agentId, status, details = {}) => {
            const now = Date.now();

            // Atualizar monitoramento
            const monitorData = baileysStatusMonitor.get(agentId) || {};
            baileysStatusMonitor.set(agentId, {
                ...monitorData,
                realStatus: status,
                lastUpdate: now,
                ...details
            });

            // Atualizar banco de dados baseado no status real do Baileys
            try {
                const updateFields = ['status = ?', 'last_activity = NOW()', 'updated_at = NOW()'];
                const updateValues = [status];

                if (details.phoneNumber) {
                    updateFields.push('phone_number = ?');
                    updateValues.push(details.phoneNumber);
                }

                if (details.errorMessage) {
                    updateFields.push('error_message = ?');
                    updateValues.push(details.errorMessage);
                } else if (status === 'connected') {
                    updateFields.push('error_message = NULL');
                }

                if (status === 'connected' && !details.skipConnectedAt) {
                    updateFields.push('connected_at = NOW()');
                }

                updateValues.push(agentId);

                await db.query(
                    `UPDATE whatsapp_instances SET ${updateFields.join(', ')} WHERE agent_id = ? AND provider = 'baileys'`,
                    updateValues
                );
                console.log(`✅ [${agentId}] Status atualizado no banco baseado no Baileys: ${status}`);
            } catch (dbError) {
                console.warn(`⚠️ [${agentId}] Erro ao atualizar banco com status do Baileys:`, dbError.message);
            }
        },

        /**
         * 🆕 CALLBACK: onBaileysHeartbeat
         * Chamado periodicamente para manter heartbeat do Baileys
         * Verifica status real e atualiza monitoramento
         */
        onBaileysHeartbeat: async (agentId, isConnected, details = {}) => {
            const now = Date.now();
            const monitorData = baileysStatusMonitor.get(agentId);

            if (!monitorData) {
                // Se não há monitoramento, criar um novo
                baileysStatusMonitor.set(agentId, {
                    lastHeartbeat: now,
                    realStatus: isConnected ? 'connected' : 'disconnected',
                    lastUpdate: now,
                    ...details
                });
                return;
            }

            // Atualizar heartbeat
            monitorData.lastHeartbeat = now;
            monitorData.realStatus = isConnected ? 'connected' : 'disconnected';
            Object.assign(monitorData, details);

            // 🆕 Se conectado, atualizar banco periodicamente (a cada 2 minutos - mais frequente)
            if (isConnected && (now - (monitorData.lastDbUpdate || 0)) > 120000) {
                try {
                    await db.query(
                        `UPDATE whatsapp_instances
                         SET status = 'connected',
                             last_activity = NOW(),
                             updated_at = NOW()
                         WHERE agent_id = ? AND provider = 'baileys'`,
                        [agentId]
                    );
                    monitorData.lastDbUpdate = now;
                } catch (dbError) {
                    console.warn(`⚠️ [${agentId}] Erro ao atualizar heartbeat no banco:`, dbError.message);
                }
            }
        },

        /**
         * CALLBACK: onDisconnected
         * Chamado quando o WhatsApp desconecta
         * Gerencia reconexão automática e limpeza de recursos
         * 🆕 Parâmetro shouldInvalidateInstance: indica se a instância deve ser invalidada após falhas de reconexão
         */
        onDisconnected: async (agentId, reason, canReconnect = false, shouldInvalidateInstance = false) => {
            // 🛡️ Proteção contra processamento duplicado
            const now = Date.now();
            const lastProcessed = disconnectionProcessing.get(agentId);
            if (lastProcessed && (now - lastProcessed) < DISCONNECTION_DEBOUNCE) {
                console.log(`⏭️ [${agentId}] Desconexão ignorada (processada há ${Math.round((now - lastProcessed) / 1000)}s)`);
                return; // Ignorar se foi processada recentemente
            }
            disconnectionProcessing.set(agentId, now);

            console.log(`🔌 WhatsApp desconectado ${agentId} - Razão: ${reason || 'desconhecida'} - Reconexão: ${canReconnect} - Invalidar: ${shouldInvalidateInstance}`);

            // ✅ IMPORTANTE: NÃO remove do Map clients imediatamente
            // Mantém a instância para reconexão automática
            // Apenas atualiza status para 'disconnected'

            statuses.set(agentId, 'disconnected');

            // 🆕 ATUALIZAR MONITORAMENTO DO BAILEYS (independente do WebSocket)
            const monitorData = baileysStatusMonitor.get(agentId) || {};
            baileysStatusMonitor.set(agentId, {
                ...monitorData,
                realStatus: 'disconnected',
                lastUpdate: Date.now(),
                disconnectReason: reason
            });

            // 🆕 ATUALIZAR BANCO DE DADOS BASEADO NO STATUS REAL DO BAILEYS
            if (baileysConfig.onBaileysStatusChange) {
                await baileysConfig.onBaileysStatusChange(agentId, 'disconnected', {
                    errorMessage: reason || 'Desconectado',
                    requiresNewQr: shouldInvalidateInstance
                });
            }

            // 🆕 ORGANIZAÇÃO: Separar desconexão do socket da invalidação da instância
            // O socket pode desconectar temporariamente sem invalidar a sessão do Baileys

            // 🆕 REMOVIDO: Tentativa de reconexão antes de invalidar
            // Isso causava loops e delays desnecessários. Se shouldInvalidateInstance é true,
            // significa que o BaileysClient já determinou que a sessão não pode ser recuperada.
            // A reconexão será gerenciada pelo próprio BaileysClient via _attemptReconnect.

            // 🆕 Determinar se requer intervenção do usuário baseado no motivo e flag de invalidação
            const requiresNewQr = shouldInvalidateInstance || (reason && (
                reason.includes('STREAM_ERRORED') || // Erro 515 após conexão estabelecida
                reason.includes('SESSION_INVALID') || // Erro 428
                (reason.includes('expirada') && !reason.includes('socket')) // Sessão realmente expirada (não apenas socket)
            ));

            // ✅ Erros fatais que deletam instância completamente
            const fatalErrors = [
                'STREAM_ERRORED',
                'SESSION_INVALID'
            ];

            const isFatal = fatalErrors.some(fatal => reason && reason.includes(fatal)) || shouldInvalidateInstance;

            if (isFatal && shouldInvalidateInstance) {
                console.log(`🚨 [${agentId}] ERRO FATAL detectado - invalidando instância: ${reason}`);

                // Limpar recursos do servidor
                await cleanupClient(agentId, 'fatal_error');

                // Atualizar banco de dados
                try {
                    await db.query(
                        `UPDATE whatsapp_instances
                         SET status = 'disconnected',
                             error_message = ?,
                             qr_code = NULL,
                             phone_number = NULL,
                             updated_at = NOW()
                        WHERE agent_id = ? AND provider = 'baileys'`,
                        [`Sessão invalidada: ${reason}`, agentId]
                    );
                } catch (dbError) {
                    console.warn(`⚠️ [${agentId}] Erro ao atualizar banco: ${dbError.message}`);
                }

                return;
            }

            // Para outros erros que requerem novo QR, manter cliente mas atualizar status
            if (requiresNewQr && !isFatal) {
                console.log(`⚠️ Erro requer novo QR para ${agentId}: ${reason}`);
                // Não remover cliente - permitir reconexão manual pelo usuário
            }

            const eventData = {
                agent_id: agentId,
                status: 'disconnected',
                requires_new_qr: requiresNewQr,
                can_reconnect: canReconnect, // ✅ Indica se reconexão é possível
                message: requiresNewQr
                    ? 'Conexão terminada. Gere um novo QR code.'
                    : canReconnect
                        ? 'Conexão temporariamente interrompida. Reconexão automática em andamento...'
                        : 'Conexão temporariamente interrompida.',
                timestamp: Date.now()
            };

            io.to(agentId).emit('status', eventData);

            // ✅ RECONEXÃO AUTOMÁTICA para erros temporários
            // IMPORTANTE: A reconexão será gerenciada pelo BaileysClient baseado em:
            // - Status no banco (deve ser 'connected')
            // - Presença de keys salvas
            // O server.js apenas permite que o BaileysClient tente reconectar
            if (canReconnect && !requiresNewQr) {
                // Aguardar um pouco antes de tentar reconectar
                setTimeout(async () => {
                    try {
                        // Verificar se o cliente ainda existe
                        const existingClient = clients.get(agentId);
                        if (existingClient) {
                            // ✅ Reinicializar o cliente existente
                            // O BaileysClient verificará internamente:
                            // - Se status no banco é 'connected'
                            // - Se há keys salvas
                            // - Se não, limpará sessão e forçará novo QR
                            await existingClient.initialize(true); // isReconnect = true
                        } else {
                            console.log(`⚠️ Cliente ${agentId} não encontrado, pulando reconexão`);
                        }
                    } catch (reconnectError) {
                        console.error(`❌ Falha na reconexão automática para ${agentId}:`, reconnectError.message);

                        // ✅ Se erro for relacionado a sessão não confirmada, limpar recursos
                        if (reconnectError.message.includes('Sessão já está ativa') ||
                            reconnectError.message.includes('sem keys salvas')) {
                            console.log(`🧹 Limpando recursos para ${agentId} após falha de reconexão`);
                            await cleanupClient(agentId, 'error');
                        } else {
                            console.log(`🛑 Abortando tentativas de reconexão automática para ${agentId}`);
                        }
                    }
                }, 3000); // 3 segundos de espera
            }
        },

        /**
         * CALLBACK: onMessage
         * Chamado quando uma mensagem é recebida via WhatsApp
         * Processa mensagens recebidas e as envia para o Laravel
         */
        onMessage: async (msg, agentId) => {
            await handleIncomingMessageBaileys(msg, agentId);
        },

        onMessageStatusUpdate: async (updates, agentId) => {
            await handleOutgoingMessageStatus(updates, agentId);
        },

        onMessageReceiptUpdate: async (updates, agentId) => {
            await handleOutgoingMessageReceipts(updates, agentId);
        }
    };

    try {
        const client = new BaileysClient(baileysConfig);
        // ✅ Modo recuperação usa isReconnect=true: reaproveita credenciais do disco
        // (se não houver credenciais válidas, o Baileys gera novo QR normalmente)
        await client.initialize(!freshSession);

        clients.set(agentId, client);
        console.log(`✅ Sessão inicializada para ${agentId}`);

    } catch (error) {
        console.error(`❌ Erro ao inicializar sessão para ${agentId}:`, error.message);

        if (typeof global !== 'undefined' && global.activeSessions) {
            global.activeSessions.delete(agentId);
        }
        if (typeof global !== 'undefined' && global.keysCache) {
            global.keysCache.delete(agentId);
        }

        io.to(agentId).emit('status', {
            agent_id: agentId,
            status: 'error',
            error: error.message,
            requires_new_qr: true
        });
    }
}

io.on('connection', (socket) => {
    socket.on('disconnect', (reason) => {

        // 🆕 IMPORTANTE: Desconexão do WebSocket NÃO deve afetar a instância do Baileys
        // O Baileys continua funcionando independentemente do WebSocket
        // Apenas limpar referências do socket, mas manter a instância do Baileys ativa

        if (socket.agentId) {
            const agentId = socket.agentId;
            const client = clients.get(agentId);

            // 🆕 VERIFICAR STATUS REAL DO BAILEYS (não do WebSocket)
            const baileysStatus = baileysStatusMonitor.get(agentId);
            const realBaileysStatus = baileysStatus?.realStatus || 'unknown';

            console.log(`🔌 [${agentId}] WebSocket desconectado (razão: ${reason}) - Status do Baileys: ${realBaileysStatus}`);

            // 🆕 Se o Baileys está conectado, apenas logar - não fazer nada
            if (realBaileysStatus === 'connected' && client && client.isConnected) {
                console.log(`✅ [${agentId}] Baileys continua conectado - WebSocket desconectado não afeta a instância`);
                // Não fazer nada - Baileys continua funcionando
                return;
            }

            // 🆕 Se não há cliente Baileys ativo, apenas limpar referências do socket
            if (!client) {
                console.log(`ℹ️ [${agentId}] Nenhum cliente Baileys ativo - apenas limpando referências do socket`);
                // ✅ Se cliente não existe mais, limpar recursos relacionados ao socket apenas
                if (typeof global !== 'undefined' && global.activeSessions) {
                    global.activeSessions.delete(agentId);
                }
            }
        }
    });

    socket.on('initialize', async (config) => {
        // 🔐 MELHORIA: Rate limiting para inicialização
        if (!checkRateLimit(socket.id, 'initialize')) {
            socket.emit('status', { status: 'error', error: 'Rate limit excedido' });
            return;
        }

        try {
            if (!config?.agentId) {
                console.error(`❌ Config inválida`);
                socket.emit('status', { status: 'error', error: 'Configuração inválida' });
                return;
            }

            const agentId = config.agentId;
            const authorization = await authorizeSocketAgent(socket, agentId);
            if (!authorization) {
                emitSocketAuthorizationError(socket, agentId);
                return;
            }

            // 🚫 SOCKET NUNCA INICIALIZA SESSÃO DIRETAMENTE
            // Socket apenas observa e reporta status atual
            await safeInitializeSession(agentId, authorization.ownerUserId, socket);

        } catch (error) {
            console.error(`❌ Erro na solicitação de inicialização:`, error.message);
            socket.emit('status', { status: 'error', error: error.message });
        }
    });

    // 🆕 Evento para ingressar em múltiplas salas de agentes simultaneamente (usado na tela de Chats)
    socket.on('join-agents', async (data) => {
        try {
            const agentIds = Array.isArray(data?.agentIds) ? data.agentIds : (data?.agentId ? [data.agentId] : []);
            for (const id of agentIds) {
                if (id && typeof id === 'string' && await authorizeSocketAgent(socket, id)) {
                    socket.join(id);
                    console.log(`🔌 [SOCKET] Cliente ${socket.id} ingressou na sala do agente ${id}`);
                }
            }
        } catch (e) {
            console.error('❌ Erro ao ingressar nas salas de agentes:', e.message);
        }
    });

    // 🆕 NOVO EVENTO: Apenas consultar status (sem inicializar)
    socket.on('get-session-status', async (config) => {
        try {
            if (!config?.agentId) {
                return;
            }

            const agentId = config.agentId;
            if (!await authorizeSocketAgent(socket, agentId)) {
                emitSocketAuthorizationError(socket, agentId);
                return;
            }
            socket.join(agentId); // Garante que está na sala para receber eventos do agente
            const currentStatus = statuses.get(agentId) || 'disconnected';

            socket.emit('session-status', {
                agent_id: agentId,
                status: currentStatus,
                timestamp: Date.now()
            });

        } catch (error) {
            console.error(`❌ Erro ao consultar status:`, error.message);
        }
    });


    socket.on('disconnectUser', async (agent_id) => {
        console.log(`🚪 Logout completo solicitado pelo usuário para ${agent_id}`);

        if (!await authorizeSocketAgent(socket, agent_id)) {
            emitSocketAuthorizationError(socket, agent_id);
            return;
        }

        const client = clients.get(agent_id);
        if (client) {
            try {
                // ✅ Logout completo usando método do BaileysClient
                await client.logout();
                console.log(`✅ Logout completo realizado para ${agent_id}`);
            } catch (err) {
                console.error(`❌ Erro durante logout para ${agent_id}:`, err.message);
                // Mesmo com erro, continua com limpeza
                try {
                    await client.disconnect();
                    console.log(`✅ Cliente ${agent_id} desconectado (fallback)`);
                } catch (disconnectErr) {
                    console.warn(`⚠️ Falha no disconnect fallback para ${agent_id}:`, disconnectErr.message);
                }
            }
        } else {
            console.warn(`⚠️ Cliente ${agent_id} não encontrado para logout`);
        }

        // ✅ Limpeza completa usando função dedicada
        // Isso remove: cliente, status, keysCache, activeSessions, diretório Auth
        await cleanupClient(agent_id, 'logout');
    });

    // ========================================
    // NOVOS EVENTOS PARA COMPATIBILIDADE COM MANAGE.BLADE.PHP
    // ========================================

    /**
     * Evento: connect-instance
     * Alias para 'initialize' - usado pelo frontend manage.blade.php
     */
    socket.on('connect-instance', async (config) => {
        try {
            console.log(`🔌 [connect-instance] Solicitação recebida:`, config);

            if (!config?.agentId) {
                console.error(`❌ Config inválida em connect-instance:`, config);
                socket.emit('status', {
                    agent_id: config?.agentId,
                    status: 'error',
                    error: 'Configuração inválida',
                    timestamp: Date.now()
                });
                return;
            }

            const agentId = config.agentId;
            const authorization = await authorizeSocketAgent(socket, agentId);
            if (!authorization) {
                emitSocketAuthorizationError(socket, agentId);
                return;
            }
            console.log(`🚀 [connect-instance] Inicializando sessão para ${agentId}`);

            // Reutiliza a lógica existente de inicialização
            await safeInitializeSession(agentId, authorization.ownerUserId, socket);

        } catch (error) {
            console.error(`❌ Erro em connect-instance:`, error.message);
            socket.emit('status', {
                agent_id: config?.agentId,
                status: 'error',
                error: error.message,
                timestamp: Date.now()
            });
        }
    });

    /**
     * Evento: disconnect-instance
     * Alias para 'disconnectUser' - usado pelo frontend manage.blade.php
     */
    socket.on('disconnect-instance', async (config) => {
        try {
            const agentId = config?.agentId || config?.agent_id;

            if (!agentId) {
                console.error(`❌ AgentId não fornecido em disconnect-instance`);
                return;
            }

            if (!await authorizeSocketAgent(socket, agentId)) {
                emitSocketAuthorizationError(socket, agentId);
                return;
            }

            if (!await ensureBaileysProvider(agentId)) {
                socket.emit('status', {
                    agent_id: agentId,
                    status: 'provider_external',
                    message: 'A desconexão local não se aplica a uma instância Z-API.',
                    timestamp: Date.now()
                });
                return;
            }

            console.log(`🚪 [disconnect-instance] Logout solicitado para ${agentId}`);

            const client = clients.get(agentId);
            if (client) {
                try {
                    await client.logout();
                    console.log(`✅ [disconnect-instance] Logout completo para ${agentId}`);
                } catch (err) {
                    console.error(`❌ Erro durante logout em disconnect-instance:`, err.message);
                    try {
                        await client.disconnect();
                        console.log(`✅ Cliente ${agentId} desconectado (fallback)`);
                    } catch (disconnectErr) {
                        console.warn(`⚠️ Falha no disconnect fallback:`, disconnectErr.message);
                    }
                }
            } else {
                console.warn(`⚠️ Cliente ${agentId} não encontrado para disconnect-instance`);
            }

            // Limpeza completa
            await cleanupClient(agentId, 'logout');

        } catch (error) {
            console.error(`❌ Erro em disconnect-instance:`, error.message);
        }
    });

    /**
     * Evento: restart-instance
     * Valida e reconecta a instância SEM desconectar socket ou deletar arquivos
     * - Mantém usuário conectado ao Socket.IO
     - Mantém cliente no Map
     * - Valida se keys estão ativas
     * - Valida se sessão do Baileys está válida
     * - Reconecta se necessário
     * - Atualiza status no banco de dados
     */
    socket.on('restart-instance', async (config) => {
        try {
            const agentId = config?.agentId || config?.agent_id;

            if (!agentId) {
                console.error(`❌ AgentId não fornecido em restart-instance`);
                socket.emit('status', {
                    agent_id: agentId,
                    status: 'error',
                    error: 'AgentId não fornecido',
                    timestamp: Date.now()
                });
                return;
            }

            const authorization = await authorizeSocketAgent(socket, agentId);
            if (!authorization) {
                emitSocketAuthorizationError(socket, agentId);
                return;
            }
            const userId = authorization.ownerUserId;

            if (!await ensureBaileysProvider(agentId, userId, socket)) {
                return;
            }


            // ✅ GARANTIR QUE SOCKET ESTÁ NA ROOM (não desconectar)
            if (!socket.rooms.has(agentId)) {
                socket.join(agentId);
                socket.agentId = agentId;
            }

            // ✅ VERIFICAR STATUS NO BANCO DE DADOS
            let dbStatus = null;
            let dbPhoneNumber = null;
            try {
                // 🔧 MELHORIA: Quando usar API do sistema, buscar por agent_id independente do user_id
                const useSystemApi = await shouldUseSystemApi();
                let query, params;

                if (useSystemApi) {
                    // Buscar por agent_id apenas quando usar API do sistema
                    query = 'SELECT status, phone_number FROM whatsapp_instances WHERE agent_id = ?';
                    params = [agentId];
                    console.log(`🔧 [${agentId}] Usando query simplificada para API do sistema (restart-instance)`);
                } else {
                    // Query normal com validação de user_id
                    query = 'SELECT status, phone_number FROM whatsapp_instances WHERE agent_id = ? AND user_id = ?';
                    params = [agentId, userId];
                }

                const [statusRows] = await db.query(query, params);
                if (statusRows && statusRows.length > 0) {
                    dbStatus = statusRows[0].status;
                    dbPhoneNumber = statusRows[0].phone_number;
                }

                console.log(`🔍 [${agentId}] Status do banco (restart): ${dbStatus}, phone: ${dbPhoneNumber}`);
            } catch (error) {
                console.warn(`⚠️ [${agentId}] Erro ao consultar status no banco:`, error.message);
            }

            const client = clients.get(agentId);

            if (client) {
                // ✅ CLIENTE EXISTE - VALIDAR E RECONECTAR SE NECESSÁRIO
                console.log(`✅ [restart-instance] Cliente ${agentId} encontrado em memória`);

                // Verificar estado atual do socket Baileys
                const wsReadyState = client.socket?.ws?.readyState;
                const isSocketConnected = wsReadyState === 1; // OPEN
                const isClientConnected = client.isConnected && client._connectionEstablished;

                // 📊 Log comentado para reduzir poluição - descomente se precisar debug
                // console.log(`📊 [restart-instance] Estado atual:`, {
                //     isClientConnected,
                //     isSocketConnected,
                //     wsReadyState,
                //     dbStatus,
                //     phoneNumber: client.phoneNumber || dbPhoneNumber
                // });

                // Se está conectado e funcionando, apenas atualizar status no banco
                if (isClientConnected && isSocketConnected) {
                    console.log(`✅ [restart-instance] Cliente ${agentId} já está conectado - apenas validando status`);

                    // Atualizar status no banco para garantir consistência
                    try {
                        // 🔧 MELHORIA: Quando usar API do sistema, atualizar independente do user_id
                        const useSystemApi = await shouldUseSystemApi();
                        let updateQuery, updateParams;

                        if (useSystemApi) {
                            // Atualizar por agent_id apenas quando usar API do sistema
                            updateQuery = `UPDATE whatsapp_instances
                                         SET status = 'connected',
                                             last_activity = NOW(),
                                             updated_at = NOW()
                                         WHERE agent_id = ?`;
                            updateParams = [agentId];
                            console.log(`🔧 [${agentId}] Usando update simplificado para API do sistema (restart-instance)`);
                        } else {
                            // Update normal com validação de user_id
                            updateQuery = `UPDATE whatsapp_instances
                                         SET status = 'connected',
                                             last_activity = NOW(),
                                             updated_at = NOW()
                                         WHERE agent_id = ? AND user_id = ?`;
                            updateParams = [agentId, userId];
                        }

                        await db.query(updateQuery, updateParams);
                        statuses.set(agentId, 'connected');

                        // Emitir status atualizado
                        socket.emit('status', {
                            agent_id: agentId,
                            status: 'connected',
                            phone_number: client.phoneNumber || dbPhoneNumber,
                            message: 'Instância validada e ativa',
                            timestamp: Date.now()
                        });

                        console.log(`✅ [restart-instance] Status atualizado no banco para ${agentId}`);
                    } catch (error) {
                        console.warn(`⚠️ [${agentId}] Erro ao atualizar status no banco:`, error.message);
                    }

                    return; // Não precisa reconectar
                }

                // ✅ CLIENTE EXISTE MAS NÃO ESTÁ CONECTADO - TENTAR RECONECTAR
                console.log(`🔄 [restart-instance] Cliente ${agentId} não está conectado - tentando reconectar...`);

                // Limpar socket antigo se existir (mas manter cliente no Map)
                if (client.socket && !isSocketConnected) {
                    try {
                        // Remover listeners para evitar vazamentos
                        client.socket.ev.removeAllListeners();
                        // Não fechar completamente - apenas limpar referência
                        client.socket = null;
                        console.log(`🧹 Limpou socket antigo para ${agentId}`);
                    } catch (err) {
                        console.warn(`⚠️ Erro ao limpar socket antigo:`, err.message);
                    }
                }

                // Resetar flags de conexão
                client.isConnected = false;
                client._connectionEstablished = false;

                // Tentar reconectar usando keys existentes
                try {
                    console.log(`🔄 [restart-instance] Reinicializando BaileysClient para ${agentId}...`);

                    // ✅ Reinicializar o cliente existente (reutiliza keys salvas)
                    // O BaileysClient vai:
                    // - Verificar se há keys salvas
                    // - Validar se keys estão ativas
                    // - Reconectar se possível
                    // - Gerar novo QR se necessário
                    await client.initialize(true); // isReconnect = true

                    console.log(`✅ [restart-instance] Cliente ${agentId} reinicializado com sucesso`);
                    // O BaileysClient vai emitir os eventos de status automaticamente

                } catch (reconnectError) {
                    console.error(`❌ [restart-instance] Erro ao reconectar ${agentId}:`, reconnectError.message);

                    // Se falhar, atualizar status no banco
                    try {
                        // 🔧 MELHORIA: Quando usar API do sistema, atualizar independente do user_id
                        const useSystemApi = await shouldUseSystemApi();
                        let errorUpdateQuery, errorUpdateParams;

                        if (useSystemApi) {
                            // Atualizar por agent_id apenas quando usar API do sistema
                            errorUpdateQuery = `UPDATE whatsapp_instances
                                              SET status = 'disconnected',
                                                  error_message = ?,
                                                  last_activity = NOW(),
                                                  updated_at = NOW()
                                              WHERE agent_id = ?`;
                            errorUpdateParams = [`Erro ao reconectar: ${reconnectError.message}`, agentId];
                            console.log(`🔧 [${agentId}] Usando error update simplificado para API do sistema`);
                        } else {
                            // Update normal com validação de user_id
                            errorUpdateQuery = `UPDATE whatsapp_instances
                                              SET status = 'disconnected',
                                                  error_message = ?,
                                                  last_activity = NOW(),
                                                  updated_at = NOW()
                                              WHERE agent_id = ? AND user_id = ?`;
                            errorUpdateParams = [`Erro ao reconectar: ${reconnectError.message}`, agentId, userId];
                        }

                        await db.query(errorUpdateQuery, errorUpdateParams);
                        statuses.set(agentId, 'disconnected');

                        socket.emit('status', {
                            agent_id: agentId,
                            status: 'disconnected',
                            error: reconnectError.message,
                            requires_new_qr: false,
                            timestamp: Date.now()
                        });
                    } catch (dbError) {
                        console.error(`❌ Erro ao atualizar status no banco:`, dbError.message);
                    }
                }

            } else {
                // ✅ CLIENTE NÃO EXISTE - CRIAR NOVA SESSÃO (após restart do servidor)
                console.log(`🚀 [restart-instance] Cliente ${agentId} não encontrado - criando nova sessão...`);

                // Verificar se há keys salvas no banco
                const hasKeysInDb = dbStatus === 'connected' || dbStatus === 'connecting';

                if (hasKeysInDb) {
                    console.log(`🔄 [restart-instance] Status no banco indica sessão ativa - tentando recuperar...`);
                }

                // Usar safeInitializeSession que vai:
                // - Verificar status no banco
                // - Verificar se há keys salvas
                // - Criar novo BaileysClient
                // - Tentar reconectar usando keys existentes
                // ✅ Reiniciar é intenção de RECONECTAR — preservar credenciais
                await safeInitializeSession(agentId, userId, socket, { freshSession: false });
            }

        } catch (error) {
            console.error(`❌ Erro em restart-instance:`, error.message);
            console.error(`❌ Stack trace:`, error.stack);

            socket.emit('status', {
                agent_id: config?.agentId || config?.agent_id,
                status: 'error',
                error: error.message,
                timestamp: Date.now()
            });
        }
    });

    /**
     * EVENTO: sendMessageUser
     * Envia mensagem do agente para um usuário específico via WhatsApp
     * Usado quando o agente precisa responder uma conversa
     */
    socket.on('sendMessageUser', async (payload) => {
        if (!payload?.agentId || !await authorizeSocketAgent(socket, payload.agentId)) {
            emitSocketAuthorizationError(socket, payload?.agentId);
            return;
        }

        if (!await ensureBaileysProvider(payload.agentId, null, socket)) {
            socket.emit('message-error', {
                agent_id: payload.agentId,
                error: 'A instância está configurada para usar outro provedor.',
                provider_conflict: true,
                timestamp: Date.now()
            });
            return;
        }

        const client = clients.get(payload.agentId);
        if (!client || !client.isConnected) {
            return console.warn(`Cliente não disponível ou não conectado para: ${payload.agentId}`);
        }

        const rateCheck = checkMessageRateLimit(payload.agentId, payload.to || payload.from);
        if (!rateCheck.allowed) {
            socket.emit('message-error', {
                agent_id: payload.agentId,
                error: rateCheck.reason || 'Limite de mensagens excedido. Aguarde antes de tentar novamente.',
                timestamp: Date.now()
            });
            return;
        }

        try {
            const markedResponse = `${payload.message}${BOT_RESPONSE_MARKER}`;
            await client.sendMessage(payload.to || payload.from, markedResponse);
            recordMessageSent(payload.agentId, payload.to || payload.from);
        } catch (err) {
            if (err?.deliveryUncertain === true) {
                recordMessageSent(payload.agentId, payload.to || payload.from);
            } else {
                releaseMessageRateLimit(payload.agentId);
            }
            console.error(`Erro ao enviar mensagem para ${payload.to}:`, err.message);
        }
    });

    /**
     * Evento: send-message
     * Envia mensagem via WhatsApp - usado pelo frontend manage.blade.php
     */
    socket.on('send-message', async (payload) => {
        let rateReserved = false;
        // 🔐 MELHORIA: Rate limiting para mensagens
        if (!checkRateLimit(socket.id, 'send-message')) {
            socket.emit('message-error', {
                error: 'Rate limit excedido. Tente novamente em alguns minutos.',
                timestamp: Date.now()
            });
            return;
        }

        try {
            const agentId = payload?.agentId || payload?.agent_id;
            // 📱 CORREÇÃO: Identificar corretamente destinatário vs remetente
            const recipientIdentifier = payload?.to || payload?.phone || payload?.from; // LID preferencialmente, ou telefone
            const recipientType = payload?.recipient_type || 'phone'; // 🆕 Tipo: 'lid' ou 'phone'
            const senderPhone = clients.get(agentId)?.phoneNumber || null; // Remetente (nosso número)
            const message = payload?.message || payload?.text;

            // 🔐 MELHORIA: Validação obrigatória de todos os campos + autorização
            if (!agentId || !recipientIdentifier || !message) {
                console.error(`❌ Dados incompletos em send-message:`, payload);
                socket.emit('message-error', {
                    agent_id: agentId,
                    error: 'Dados incompletos: agentId, recipientIdentifier e message são obrigatórios',
                    timestamp: Date.now()
                });
                return;
            }

            if (!await authorizeSocketAgent(socket, agentId)) {
                emitSocketAuthorizationError(socket, agentId);
                return;
            }

            if (!await ensureBaileysProvider(agentId, null, socket)) {
                socket.emit('message-error', {
                    agent_id: agentId,
                    error: 'A instância está configurada para usar outro provedor.',
                    provider_conflict: true,
                    timestamp: Date.now()
                });
                return;
            }

            // 🛡️ VALIDAÇÃO: Verificar se o identificador do destinatário é válido
            if (recipientType === 'phone') {
                // Se é telefone, validar formato
                const cleanPhone = recipientIdentifier.replace(/[^\d]/g, '');
                if (cleanPhone.length < 10) {
                    console.error(`❌ Número de telefone inválido: ${recipientIdentifier} (apenas ${cleanPhone.length} dígitos)`);
                    socket.emit('message-error', {
                        agent_id: agentId,
                        error: `Número de telefone inválido: ${recipientIdentifier}. Use um número completo com DDD.`,
                        timestamp: Date.now()
                    });
                    return;
                }
            } else if (recipientType === 'lid') {
                // Se é LID, validar formato básico
                if (!recipientIdentifier.includes('@lid')) {
                    console.error(`❌ LID inválido: ${recipientIdentifier}`);
                    socket.emit('message-error', {
                        agent_id: agentId,
                        error: `LID inválido: ${recipientIdentifier}. Deve conter '@lid'.`,
                        timestamp: Date.now()
                    });
                    return;
                }
            }

            console.log(`📤 Enviando mensagem usando ${recipientType}: ${recipientIdentifier}`);

            const client = clients.get(agentId);

            if (!client) {
                console.warn(`⚠️ Cliente ${agentId} não encontrado`);
                socket.emit('message-error', {
                    agent_id: agentId,
                    error: 'Cliente não encontrado',
                    timestamp: Date.now()
                });
                return;
            }

            if (!client.isConnected) {
                console.warn(`⚠️ Cliente ${agentId} não está conectado`);
                socket.emit('message-error', {
                    agent_id: agentId,
                    error: 'Cliente não está conectado ao WhatsApp',
                    timestamp: Date.now()
                });
                return;
            }

            const messageRateCheck = checkMessageRateLimit(agentId, recipientIdentifier);
            if (!messageRateCheck.allowed) {
                socket.emit('message-error', {
                    agent_id: agentId,
                    error: messageRateCheck.reason || 'Limite de mensagens excedido. Aguarde antes de tentar novamente.',
                    timestamp: Date.now()
                });
                return;
            }
            rateReserved = true;

            // ✅ FORMATAR IDENTIFICADOR PARA JID DO WHATSAPP
            // Se for LID, usar diretamente; se for telefone, formatar
            let formattedRecipient;
            if (recipientType === 'lid') {
                formattedRecipient = recipientIdentifier; // Já é @lid, usar diretamente
                console.log(`🎯 Usando LID diretamente: ${formattedRecipient}`);
            } else {
                formattedRecipient = formatToWhatsAppJid(recipientIdentifier, agentId);
                console.log(`📞 Formatando telefone para JID: ${recipientIdentifier} -> ${formattedRecipient}`);
            }

            // Adiciona marcador para evitar loops
            const markedMessage = `${message}${BOT_RESPONSE_MARKER}`;

            // Envia mensagem para o destinatário
            await client.sendMessage(formattedRecipient, markedMessage);
            recordMessageSent(agentId, formattedRecipient);
            rateReserved = false;

            // Confirma envio
            socket.emit('message-sent', {
                agent_id: agentId,
                to: formattedRecipient, // Destinatário correto
                from: senderPhone, // Nosso número (remetente)
                success: true,
                timestamp: Date.now()
            });

            // Notifica room do agente com informações corretas
            io.to(agentId).emit('message', {
                agent_id: agentId,
                from: senderPhone, // Nosso número (quem enviou)
                to: formattedRecipient, // Para quem foi enviada
                message: message,
                direction: 'output', // Sempre 'output' para mensagens enviadas
                timestamp: Date.now()
            });

        } catch (error) {
            if (rateReserved) {
                if (error?.deliveryUncertain === true) {
                    recordMessageSent(payload?.agentId || payload?.agent_id, payload?.to || payload?.phone || payload?.from);
                } else {
                    releaseMessageRateLimit(payload?.agentId || payload?.agent_id);
                }
            }
            console.error(`❌ Erro ao enviar mensagem:`, error.message);
            console.error(`❌ Stack trace:`, error.stack);

            socket.emit('message-error', {
                agent_id: payload?.agentId || payload?.agent_id,
                error: error.message,
                timestamp: Date.now()
            });
        }
    });

    socket.on('refresh-qr', async (payload = {}) => {
        try {
            const agentId = payload.agentId || payload.agent_id;
            if (!agentId || !await authorizeSocketAgent(socket, agentId)) {
                emitSocketAuthorizationError(socket, agentId);
                return;
            }

            const result = await refreshQRCode(agentId, socket);
            socket.emit('qr-refresh-result', { agent_id: agentId, ...result });
        } catch (error) {
            socket.emit('qr-refresh-result', {
                agent_id: payload.agentId || payload.agent_id || null,
                success: false,
                error: error.message
            });
        }
    });

    /**
     * Evento: check-instance-status
     * Verifica o status atual da instância WhatsApp
     */
    socket.on('check-instance-status', async (payload) => {
        try {
            const agentId = payload?.agentId || payload?.agent_id;

            if (!agentId) {
                socket.emit('instance-status', {
                    agent_id: null,
                    status: 'error',
                    error: 'Agent ID não fornecido',
                    timestamp: Date.now()
                });
                return;
            }

            if (!await authorizeSocketAgent(socket, agentId)) {
                emitSocketAuthorizationError(socket, agentId);
                return;
            }

            const client = clients.get(agentId);
            const status = statuses.get(agentId) || 'disconnected';

            let instanceStatus = {
                agent_id: agentId,
                status: status,
                is_connected: client ? client.isConnected : false,
                is_connecting: status === 'connecting',
                has_error: status === 'error',
                phone_number: client?.phoneNumber || null,
                qr_code: client?.qrCode || null,
                timestamp: Date.now()
            };

            // Buscar informações adicionais da instância no banco de dados
            try {
                const [instanceRows] = await db.query(
                    'SELECT * FROM whatsapp_instances WHERE agent_id = ?',
                    [agentId]
                );

                if (instanceRows && instanceRows.length > 0) {
                    const dbInstance = instanceRows[0];
                    instanceStatus = {
                        ...instanceStatus,
                        db_status: dbInstance.status,
                        connected_at: dbInstance.connected_at,
                        last_activity: dbInstance.last_activity,
                        error_message: dbInstance.error_message,
                        phone_number: dbInstance.phone_number || instanceStatus.phone_number
                    };
                }
            } catch (dbError) {
                console.warn(`⚠️ Erro ao buscar dados da instância no banco:`, dbError.message);
                // Continua sem os dados do banco
            }

            socket.emit('instance-status', instanceStatus);

        } catch (error) {
            console.error(`❌ Erro ao verificar status da instância:`, error.message);

            socket.emit('instance-status', {
                agent_id: payload?.agentId || payload?.agent_id,
                status: 'error',
                error: error.message,
                timestamp: Date.now()
            });
        }
    });
});

// 🔐 MELHORIA: Cleanup periódico do rate limiting
setInterval(() => {
    const now = Date.now();
    const cutoff = now - RATE_LIMIT_WINDOW;

    for (const [key, attempts] of rateLimits.entries()) {
        const recentAttempts = attempts.filter(time => time > cutoff);
        if (recentAttempts.length === 0) {
            rateLimits.delete(key);
        } else {
            rateLimits.set(key, recentAttempts);
        }
    }
}, 60000); // Limpa a cada minuto

// ✅ Porta e bind configuráveis via .env. O serviço fica apenas em loopback;
// o acesso externo acontece pelo proxy reverso do Apache no domínio principal.
const PORT = Number.parseInt(process.env.WHATSAPP_PORT || process.env.PORT || '3200', 10);
const HOST = process.env.WHATSAPP_BIND_HOST || '127.0.0.1';
ensureAuthRoot();
server.listen(PORT, HOST, async () => {
    console.log(`🚀 Servidor WhatsApp iniciado em ${HOST}:${PORT} - verificando conexões...`);
    console.log(`🔐 Configurações de segurança:`);
    console.log(`   - Rate limiting: ${MAX_REQUESTS_PER_MINUTE} req/min`);
    console.log(`   - CORS origins: ${CLIENT_ORIGIN.length} permitidas`);
    console.log(`   - Bind: ${HOST}:${PORT}`);

    // 🆕 Verificar conexão com banco de dados antes de iniciar
    const dbConnected = await db.testConnection();
    if (!dbConnected) {
        console.error('❌ Falha na conexão com banco de dados. Servidor pode não funcionar corretamente.');
        console.log('🔄 Continuando inicialização mesmo com erro de DB...');
    }

    // Aguardar um pouco para garantir que o servidor esteja totalmente pronto
    setTimeout(async () => {
        try {
            await autoRecoverInstances();
        } catch (error) {
            console.error('❌ Erro na recuperação automática de instâncias:', error.message);
            console.log('🔄 Servidor continuará funcionando mesmo com erro na recuperação...');
        }
    }, 2000); // 2 segundos de delay
});

/**
 * FUNÇÃO SEGURA PARA INICIALIZAR SESSÃO
 * Só cria BaileysClient se não há sessão ativa
 */
async function safeInitializeSession(agentId, userId, socket = null, options = {}) {
    if (!await ensureBaileysProvider(agentId, userId, socket)) {
        return { success: false, provider_conflict: true };
    }

    // 🛡️ 1. RATE LIMITING: Verificar tentativas de conexão
    const rateLimitCheck = checkRateLimit(agentId, 'initialize');
    if (!rateLimitCheck) {
        if (socket) {
            socket.emit('status', {
                agent_id: agentId,
                status: 'rate_limited',
                message: 'Rate limit excedido',
                requires_new_qr: false,
                timestamp: Date.now()
            });
        }
        return;
    }

    if (sessionInitializationLocks.has(agentId)) {
        console.warn(`⏳ [${agentId}] Inicialização ignorada: outra inicialização já está em andamento`);
        return;
    }

    sessionInitializationLocks.add(agentId);

    try {

    // 🔍 2. SESSION HEALTH: Validar saúde da sessão
    const healthCheck = validateSessionHealth(agentId);
    console.log(`🔍 [SESSION HEALTH] Agent ${agentId} - healthy: ${healthCheck.healthy}, canGenerateQr: ${healthCheck.canGenerateQr}, reason: ${healthCheck.reason}`);

    if (!healthCheck.healthy && !healthCheck.canGenerateQr) {
        console.warn(`⚠️ [SESSION HEALTH] Agent ${agentId} com sessão REALMENTE corrompida: ${healthCheck.reason}`);

        // Só limpa sessão se estiver REALMENTE corrompida (não pode gerar QR)
        try {
            const client = clients.get(agentId);
            if (client) {
                // Sessão corrompida deve ser isolada sem usar logout, pois
                // logout destrói as credenciais antes da auditoria.
                await client.disconnect();
                await cleanupClient(agentId, 'corrupted_session');
            }

            // Atualiza banco como corrompida
            await db.query(
                'UPDATE whatsapp_instances SET status = ?, error_message = ?, updated_at = NOW() WHERE agent_id = ?',
                ['corrupted', `Sessão corrompida: ${healthCheck.reason}`, agentId]
            );

            if (socket) {
                socket.emit('status', {
                    agent_id: agentId,
                    status: 'corrupted',
                    message: 'Sessão corrompida detectada. Contate o suporte.',
                    requires_new_qr: false, // Não pode gerar QR
                    timestamp: Date.now()
                });
            }
        } catch (error) {
            console.error(`❌ [SESSION HEALTH] Erro ao limpar sessão corrompida ${agentId}:`, error.message);
        }
        return;
    } else if (!healthCheck.healthy && healthCheck.canGenerateQr) {
        console.log(`🔄 [SESSION HEALTH] Agent ${agentId} com sessão expirada/ausente: ${healthCheck.reason} - pode gerar novo QR`);
        // Continua o processo normalmente - vai gerar novo QR
    } else {
        console.log(`✅ [SESSION HEALTH] Agent ${agentId} saudável: ${healthCheck.reason || 'OK'}`);
        // Continua o processo normalmente
    }

    // 🔒 3. Verificar status no BANCO DE DADOS
    let dbStatus = null;
    let phoneNumber = null;
    try {
        // 🔧 MELHORIA: Quando usar API do sistema, buscar por agent_id independente do user_id
        const useSystemApi = await shouldUseSystemApi();
        let query, params;

        if (useSystemApi) {
            // Buscar por agent_id apenas quando usar API do sistema
            query = 'SELECT status, phone_number FROM whatsapp_instances WHERE agent_id = ?';
            params = [agentId];
        } else {
            // Query normal com validação de user_id
            query = 'SELECT status, phone_number FROM whatsapp_instances WHERE agent_id = ? AND user_id = ?';
            params = [agentId, userId];
        }

        const [statusRows] = await db.query(query, params);
        if (statusRows && statusRows.length > 0) {
            dbStatus = statusRows[0].status;
            phoneNumber = statusRows[0].phone_number;
        }
    } catch (error) {
        console.warn(`⚠️ [${agentId}] Erro ao consultar status no banco:`, error.message);
        dbStatus = null;
        phoneNumber = null;
    }

    const currentStatus = dbStatus || statuses.get(agentId);
    console.log(`🎯 [${agentId}] Decisão baseada no status: ${currentStatus}`);

    // A ação explícita pode iniciar uma sessão nova; os fluxos internos passam
    // freshSession=false e preservam as credenciais existentes.
    console.log(`🔄 [${agentId}] Preparando inicialização (status atual: ${currentStatus || 'desconhecido'})`);

    // ✅ 4. Só aqui pode criar nova sessão
    //console.log(`🚀 [${agentId}] Inicialização AUTORIZADA — criando nova sessão`);

    const config = { userId, agentId };
    console.log(`🎯 [${agentId}] Chamando initializeSession com config:`, config);
    return await initializeSession(socket, config, options);
    } finally {
        sessionInitializationLocks.delete(agentId);
    }
}

/**
 * 🆕 REFRESH IMEDIATO DE QR CODE
 * Permite que usuário solicite novo QR Code sem esperar timeout
 * Útil para melhorar UX quando QR expira ou usuário quer tentar novamente
 */
async function refreshQRCode(agentId, socket = null) {
    try {
        console.log(`🔄 [${agentId}] Solicitação de refresh de QR Code recebida`);

        if (!await ensureBaileysProvider(agentId, null, socket)) {
            return { success: false, provider_conflict: true };
        }

        // 1. Verificar se cliente existe
        const client = clients.get(agentId);
        if (!client) {
            console.warn(`⚠️ [${agentId}] Cliente não encontrado - criando nova sessão`);

            // Se não existe, buscar userId do banco e criar nova sessão
            try {
                const [rows] = await db.query(
                    'SELECT user_id FROM whatsapp_instances WHERE agent_id = ?',
                    [agentId]
                );

                if (rows && rows.length > 0) {
                    const userId = rows[0].user_id;
                    // ✅ Refresh de QR não deve deslogar: preserva credenciais existentes
                    return await safeInitializeSession(agentId, userId, socket, { freshSession: false });
                } else {
                    throw new Error('Instância não encontrada no banco');
                }
            } catch (dbError) {
                console.error(`❌ [${agentId}] Erro ao buscar userId:`, dbError.message);
                if (socket) {
                    socket.emit('status', {
                        agent_id: agentId,
                        status: 'error',
                        message: 'Erro ao atualizar QR Code',
                        timestamp: Date.now()
                    });
                }
                return { success: false, error: dbError.message };
            }
        }

        // 2. Cliente existe - forçar refresh de QR
        console.log(`✅ [${agentId}] Cliente encontrado - forçando refresh de QR`);
        const result = await client.forceRefreshQR();

        // 3. Notificar via Socket.IO (se socket fornecido)
        if (socket) {
            socket.emit('status', {
                agent_id: agentId,
                status: 'qr_refreshing',
                message: 'Gerando novo QR Code...',
                timestamp: Date.now()
            });
        }

        console.log(`✅ [${agentId}] Refresh de QR Code concluído com sucesso`);
        return result;

    } catch (error) {
        console.error(`❌ [${agentId}] Erro ao refresh de QR Code:`, error);

        if (socket) {
            socket.emit('status', {
                agent_id: agentId,
                status: 'error',
                message: 'Erro ao atualizar QR Code',
                error: error.message,
                timestamp: Date.now()
            });
        }

        return { success: false, error: error.message };
    }
}

/**
 * Envia resposta do agente via WhatsApp
 * Processa e envia mensagens de resposta do agente para usuários
 * Inclui validações de segurança, controle de duplicação e formatação de destinatário
 * 🆕 Suporta envio de mídia (imagem, vídeo, áudio, documento)
 * @param {Object} payload - Dados da mensagem (from, to, agent_id, etc.)
 * @param {string|Object} response - Conteúdo da resposta a ser enviada ou objeto com mídia
 */
async function sendWhatsappResponse(payload, response) {
    const agentId = payload.agent_id || payload.agentId;

    if (!await ensureBaileysProvider(agentId)) return;

    // Se não há resposta, não enviar nada
    if (!response || response === null || response === undefined) {
        console.log(`🚫 [${agentId}] Sem resposta para enviar`);
        return;
    }

    // Criar uma chave estável apenas para evitar duplicação local recente.
    const serializedResponse = typeof response === 'string'
        ? response
        : (JSON.stringify(response) || String(response));
    const responseFingerprint = serializedResponse.slice(0, 300);
    const responseId = `${agentId}-${payload.to}-${responseFingerprint}`;

    // Verificar se já foi enviada recentemente
    if (!sentResponses.has(agentId)) {
        sentResponses.set(agentId, new Set());
    }

    const agentSent = sentResponses.get(agentId);
    if (agentSent.has(responseId)) {
        console.log(`🔄 [${agentId}] Resposta duplicada evitada: ${responseId}`);
        return;
    }

    // Adicionar ao cache (manter últimas 100 respostas)
    agentSent.add(responseId);
    if (agentSent.size > 100) {
        const firstId = agentSent.values().next().value;
        agentSent.delete(firstId);
    }

    // Validações básicas
    if (!(await isAgentEnabled(agentId))) {
        console.log(`🚫 [${agentId}] Agente desabilitado`);
        return;
    }

    const client = clients.get(agentId);
    if (!client?.isConnected) {
        console.log(`⚠️ [${agentId}] Cliente desconectado`);
        return;
    }

    // Verificações de segurança essenciais
    if (isContentSuspicious(payload.body)) {
        console.log(`🚫 [${agentId}] Verificações de segurança falharam`);
        return;
    }

    const rateCheck = checkMessageRateLimit(agentId, payload.from);
    if (!rateCheck.allowed) {
        console.log(`🚫 [${agentId}] Limite de saída atingido`);
        return;
    }
    let rateReserved = true;

    // Verificar WebSocket
    const wsReadyState = client.socket?.ws?.readyState;
    if (wsReadyState === 2 || wsReadyState === 3 || wsReadyState === null) {
        console.log(`🚫 [${agentId}] WebSocket desconectado`);
        releaseMessageRateLimit(agentId);
        rateReserved = false;
        await markAsCorrupted(agentId, payload, response);
        return;
    }

    // 🆕 Verificar se o chat está em pausa diretamente do banco de dados
    const contactPhone = payload.direction === 'input' ? payload.from : payload.to;

    // 🆕 Normalizar telefone: remover @s.whatsapp.net, @lid, etc, manter apenas números
    const normalizePhoneForQuery = (phone) => {
        if (!phone) return null;
        // Remover sufixos como @s.whatsapp.net, @lid, etc
        const cleaned = String(phone).split('@')[0];
        // Remover tudo que não é número
        return cleaned.replace(/[^\d]/g, '');
    };

    const normalizedContactPhone = normalizePhoneForQuery(contactPhone);

    try {
        // 🆕 Buscar chat normalizando o telefone no banco também (usando REPLACE para remover sufixos)
        // Isso garante que encontramos o chat mesmo se o telefone foi salvo com @s.whatsapp.net ou @lid
        const [chatRows] = await db.query(
            `SELECT is_pause, phone FROM chats
             WHERE agent_id = ?
             AND REPLACE(REPLACE(phone, '@s.whatsapp.net', ''), '@lid', '') LIKE ?
             LIMIT 1`,
            [agentId, `%${normalizedContactPhone}%`]
        );

        if (chatRows && chatRows.length > 0) {
            const isPause = chatRows[0].is_pause;
            // 🆕 Comparar explicitamente com 'true' (enum string)
            // O enum retorna como string 'true' ou 'false'
            if (String(isPause) === 'true') {
                console.log(`⏸️ [${agentId}] Chat em pausa - mensagem não será enviada`);
                releaseMessageRateLimit(agentId);
                rateReserved = false;
                return;
            } else {
                /* console.log(`✅ [${agentId}] Chat NÃO está em pausa - continuando processamento`, {
                    is_pause: isPause
                }); */
            }
        } else {
            console.log(`ℹ️ [${agentId}] Chat não encontrado para verificação de pausa - continuando processamento:`, {
                agent_id: agentId,
                contact_phone: contactPhone,
                normalized_phone: normalizedContactPhone
            });
        }
    } catch (pauseCheckError) {
        console.error(`❌ [${agentId}] Erro ao verificar is_pause do chat:`, pauseCheckError.message);
        console.error(`❌ [${agentId}] Stack trace:`, pauseCheckError.stack);
        // Continuar o processamento mesmo em caso de erro na verificação
    }

    try {
        // Determinar destinatário - usar TO (quem recebe a resposta)
        let recipientJid = payload.from;
        // 🆕 Verificar se resposta contém mídia
        let responseData = response;
        let isMediaResponse = false;

        // Se response é objeto com informações de mídia
        if (typeof response === 'object' && response !== null && response.mediaType) {
            isMediaResponse = true;
            responseData = response;
        } else if (typeof response === 'string' && response.includes('[MEDIA:')) {
            // Se response é string com formato [MEDIA:{...}]
            try {
                const mediaMatch = response.match(/\[MEDIA:(.+?)\]/);
                if (mediaMatch) {
                    const mediaInfo = JSON.parse(mediaMatch[1]);
                    responseData = {
                        mediaType: mediaInfo.type,
                        mediaPath: mediaInfo.path,
                        mimetype: mediaInfo.mimetype,
                        filename: mediaInfo.filename,
                        caption: mediaInfo.caption || response.replace(/\[MEDIA:.+?\]/, '').trim()
                    };
                    isMediaResponse = true;
                }
            } catch (e) {
                console.warn(`⚠️ [${agentId}] Erro ao parsear informações de mídia:`, e.message);
            }
        }

        // Enviar mensagem ou mídia
        try {
            let sendResult;

            if (isMediaResponse && responseData.mediaPath) {
                // 🆕 Enviar mídia
                const mediaFullPath = path.resolve(__dirname, '../../../storage/app', responseData.mediaPath);

                // Verificar se arquivo existe
                if (!fs.existsSync(mediaFullPath)) {
                    console.error(`❌ [${agentId}] Arquivo de mídia não encontrado: ${mediaFullPath}`);
                    // Fallback: enviar como texto
                    const fallbackText = responseData.caption || responseData.filename || '[Mídia não disponível]';
                    const markedResponse = `${fallbackText}${BOT_RESPONSE_MARKER}`;
                    sendResult = await client.sendMessage(recipientJid, markedResponse);
                } else {
                    // Enviar mídia baseado no tipo
                    switch (responseData.mediaType) {
                        case 'image':
                            sendResult = await client.sendImage(recipientJid, mediaFullPath, responseData.caption || '');
                            break;
                        case 'video':
                            sendResult = await client.sendVideo(recipientJid, mediaFullPath, responseData.caption || '');
                            break;
                        case 'audio':
                            sendResult = await client.sendAudio(recipientJid, mediaFullPath, true);
                            break;
                        case 'document':
                            sendResult = await client.sendDocument(
                                recipientJid,
                                mediaFullPath,
                                responseData.filename || 'documento',
                                responseData.mimetype || 'application/octet-stream'
                            );
                            break;
                        default:
                            // Fallback: enviar como texto
                            const fallbackText = responseData.caption || responseData.filename || '[Mídia]';
                            const markedResponse = `${fallbackText}${BOT_RESPONSE_MARKER}`;
                            sendResult = await client.sendMessage(recipientJid, markedResponse);
                    }

                }
            } else {
                // Enviar mensagem de texto normal
                const markedResponse = `${response}${BOT_RESPONSE_MARKER}`;
                sendResult = await client.sendMessage(recipientJid, markedResponse);

                // Notificar via WebSocket a resposta enviada pelo assistente
                io.to(agentId).emit('message', {
                    agent_id: agentId,
                    from: client.phoneNumber,
                    to: payload.from,
                    direction: 'output',
                    body: response,
                    message: response,
                    messageType: 'text',
                    timestamp: Date.now()
                });
            }
        } catch (sendMsgError) {
            console.error(`❌ [${agentId}] Erro ao enviar mensagem para ${recipientJid}:`, sendMsgError.message);
            console.error(`❌ [${agentId}] Stack trace:`, sendMsgError.stack);

            throw sendMsgError; // Re-throw para ser capturado pelo catch externo
        }

        // Registrar sucesso
        recordMessageSent(agentId, payload.from);
        rateReserved = false;

        // Notificar WebSocket
        io.to(agentId).emit('message', {
            agent_id: agentId,
            from: client.phoneNumber,
            to: payload.from,
            direction: 'output',
            timestamp: Date.now()
        });
    } catch (sendError) {
        if (rateReserved) {
            if (sendError?.deliveryUncertain === true) {
                recordMessageSent(agentId, payload.from);
            } else {
                releaseMessageRateLimit(agentId);
            }
            rateReserved = false;
        }
        if (sendError?.deliveryUncertain !== true) {
            // Permite nova tentativa somente quando sabemos que o socket não
            // chegou a iniciar o envio.
            agentSent.delete(responseId);
        }
        console.error(`❌ [${agentId}] Erro no envio de mensagem:`, sendError.message);
        console.error(`❌ [${agentId}] Stack trace:`, sendError.stack);
        console.error(`❌ [${agentId}] Payload:`, JSON.stringify(payload, null, 2));

        // Resultado incerto não deve ser reenviado: o WhatsApp pode ter aceito
        // a mensagem antes da conexão falhar.
        if (sendError?.deliveryUncertain === true) {
            console.warn(`⚠️ [${agentId}] Resultado do envio inconclusivo; resposta não será repetida automaticamente.`);
        } else if (sendError.message.includes('not authenticated') ||
            sendError.message.includes('session') ||
            sendError.message.includes('corrupt')) {
            console.error(`🚨 [${agentId}] Marcando instância como corrompida devido ao erro`);
            await markAsCorrupted(agentId, payload, response);
        } else {
            console.warn(`⚠️ [${agentId}] Erro temporário, adicionando à fila de mensagens pendentes`);
            addPendingMessage(agentId, payload, response);
        }
    }
}

/**
 * Verifica se o agente está habilitado (status = 'enabled')
 * @param {string} agentId - UUID do agente
 * @returns {Promise<boolean>} - true se habilitado, false caso contrário
 */
async function isAgentEnabled(agentId) {
    try {
        // 🆕 Validação mais robusta de entrada
        if (!agentId || typeof agentId !== 'string' || agentId.trim() === '' || agentId === 'undefined' || agentId === 'null') {
            console.warn('isAgentEnabled: agentId inválido ou undefined:', agentId, `(tipo: ${typeof agentId})`);
            // 🆕 Para chamadas inesperadas com undefined, permitir processamento para não bloquear
            return true;
        }

        // Validação básica de formato UUID (simplificada)
        if (!agentId || typeof agentId !== 'string' || agentId.length < 8) {
            console.warn('isAgentEnabled: agentId inválido:', agentId);
            return false;
        }

        const [rows] = await db.query('SELECT status FROM agents WHERE uuid = ? LIMIT 1', [agentId]);

        if (!rows || rows.length === 0) {
            console.log(`isAgentEnabled: Agente ${agentId} não encontrado no banco`);
            return false;
        }

        const status = rows[0].status;
        const isEnabled = status === 'enabled';

        if (!isEnabled) {
            console.log(`⚠️ [${agentId}] Agente desabilitado (status: ${status}) - ignorando mensagens`);
        }

        return isEnabled;

    } catch (error) {
        console.error(`❌ Erro em isAgentEnabled para agent ${agentId}:`, error.message);

        // 🆕 Se erro de conexão, não falhar completamente
        if (error.code === 'ECONNRESET' || error.code === 'ENOTFOUND' ||
            error.code === 'ECONNREFUSED' || error.code === 'PROTOCOL_CONNECTION_LOST') {
            console.warn(`⚠️ [${agentId}] Erro de conexão DB em isAgentEnabled, assumindo habilitado para não bloquear mensagens`);
            return true; // Em caso de erro de conexão, permitir processamento
        }

        return false;
    }
}

/**
 * Verifica se o agente está em modo pessoal e se deve processar a mensagem
 * Agentes pessoais só respondem a self-messages (própria instância conversando consigo mesma)
 * Esta função centraliza TODA a lógica de decisão para agentes pessoais
 * @param {string} agentId - UUID do agente
 * @param {Object} payload - Dados da mensagem (from, to, direction, etc.)
 * @returns {Promise<Object>} - {isPersonal: boolean, shouldProcess: boolean, reason: string}
 */
async function isAgentPersonal(agentId, payload = null) {
    try {
        // 🆕 Validação mais robusta de entrada
        if (!agentId || typeof agentId !== 'string' || agentId.trim() === '' || agentId === 'undefined' || agentId === 'null') {
            console.warn('isAgentPersonal: agentId inválido ou undefined:', agentId, `(tipo: ${typeof agentId})`);
            return { isPersonal: false, shouldProcess: true, reason: 'invalid_agent_id' };
        }

        // Validação básica de formato UUID (simplificada)
        if (!agentId || typeof agentId !== 'string' || agentId.length < 8) {
            console.warn('isAgentPersonal: agentId inválido:', agentId);
            return { isPersonal: false, shouldProcess: true, reason: 'invalid_agent_id' };
        }

        const [rows] = await db.query('SELECT is_personal FROM agents WHERE uuid = ? LIMIT 1', [agentId]);

        if (!rows || rows.length === 0) {
            console.log(`isAgentPersonal: Agente ${agentId} não encontrado no banco`);
            return { isPersonal: false, shouldProcess: true, reason: 'agent_not_found' };
        }

        const isPersonal = rows[0].is_personal === 1 ||
            rows[0].is_personal === '1' ||
            rows[0].is_personal === true ||
            rows[0].is_personal === 'true';

        // Se não é pessoal, sempre processar (lógica normal)
        if (!isPersonal) {
            return { isPersonal: false, shouldProcess: true, reason: 'not_personal_agent' };
        }

        // ✅ É agente pessoal - aplicar lógica específica
        if (!payload) {
            // Sem payload, assumir que deve processar (compatibilidade)
            return { isPersonal: true, shouldProcess: true, reason: 'personal_agent_no_payload' };
        }

        const { from, to, direction } = payload;

        // Obter informações da instância conectada
        const client = clients.get(agentId);
        const instancePhone = client?.phoneNumber;
        const instanceLid = client?.instanceLid;

        if (!instancePhone) {
            console.warn(`⚠️ [${agentId}] Número da instância não encontrado para validação pessoal`);
            return { isPersonal: true, shouldProcess: false, reason: 'instance_phone_not_found' };
        }

        // Função para normalizar números (remover formatação)
        const normalizePhone = (phone) => phone ? phone.replace(/[^\d]/g, '') : '';

        const normalizedFrom = normalizePhone(from);
        const normalizedTo = normalizePhone(to);
        const normalizedInstance = normalizePhone(instancePhone);

        // 🆕 Verificar se é self-message usando múltiplas abordagens
        let isSelfMessage = false;

        // Abordagem 1: Comparação direta de números normalizados (output direction)
        if (direction === 'output' && normalizedFrom === normalizedInstance) {
            // Verificar se o destino é a própria instância
            if (normalizedTo === normalizedInstance) {
                isSelfMessage = true;
            }
            // Ou se o destino é o @lid da própria instância
            else if (instanceLid && to === `${instanceLid}@s.whatsapp.net`) {
                isSelfMessage = true;
            }
            // Ou se o destino é apenas o @lid da própria instância
            else if (instanceLid && to === instanceLid) {
                isSelfMessage = true;
            }
            // 🆕 NORMALIZAR instanceLid para formato @lid e comparar
            else if (instanceLid) {
                // Remover :Number do instanceLid se existir e adicionar @lid
                const normalizedInstanceLid = instanceLid.split(':')[0] + '@lid';
                if (to === normalizedInstanceLid) {
                    isSelfMessage = true;
                }
            }
        }

        // Agentes pessoais SÓ respondem a self-messages
        const shouldProcess = isSelfMessage;

        return {
            isPersonal: true,
            shouldProcess,
            reason: shouldProcess ? 'personal_self_message' : 'personal_not_self_message'
        };

    } catch (error) {
        console.error(`❌ Erro em isAgentPersonal para agent ${agentId}:`, error.message);

        // 🆕 Se erro de conexão, assumir modo não pessoal por segurança
        if (error.code === 'ECONNRESET' || error.code === 'ENOTFOUND' ||
            error.code === 'ECONNREFUSED' || error.code === 'PROTOCOL_CONNECTION_LOST') {
            console.warn(`⚠️ [${agentId}] Erro de conexão DB em isAgentPersonal, assumindo modo não pessoal`);
            return { isPersonal: false, shouldProcess: true, reason: 'db_connection_error' };
        }

        // Em caso de erro, assumir não pessoal para não bloquear
        return { isPersonal: false, shouldProcess: true, reason: 'error_fallback' };
    }
}
