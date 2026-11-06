'use strict';
import dotenv from 'dotenv';
import { execFile } from 'child_process';
import { promisify } from 'util';
import fs from 'fs/promises';
import path from 'path';
import { fileURLToPath } from 'url';

const execFilePromise = promisify(execFile);
const __dirname = path.dirname(fileURLToPath(import.meta.url));
dotenv.config({ path: path.resolve(__dirname, '../../../.env') });

let cachedArtisanPath = null;
const processingByAgent = new Map();
const MAX_PENDING_PER_AGENT = 20;

/**
 * Localiza e armazena em cache o caminho do artisan
 */
async function getArtisanPath() {
    if (cachedArtisanPath) {
        return cachedArtisanPath;
    }

    if (process.env.MIX_ARTISAN_PATH) {
        try {
            await fs.access(process.env.MIX_ARTISAN_PATH);
            cachedArtisanPath = process.env.MIX_ARTISAN_PATH;
            return cachedArtisanPath;
        } catch {
            // Segue para fallback
        }
    }

    const possiblePaths = [
        path.resolve(__dirname, '../../../artisan'),
        'C:\\wamp64\\www\\public_html\\artisan',
        path.resolve(__dirname, '../../../../artisan'),
        path.resolve('artisan')
    ];

    for (const p of possiblePaths) {
        try {
            await fs.access(p);
            cachedArtisanPath = p;
            return cachedArtisanPath;
        } catch {
            // Continua busca
        }
    }

    return null;
}

/**
 * Processa uma mensagem recebida e aciona o pipeline do Laravel
 */
async function handleIncomingMessage(payload, sendResponseCallback) {
    const agentId = String(payload.agent_id || 'unknown');
    const previous = processingByAgent.get(agentId) || Promise.resolve();
    const queuedCount = processingByAgent.get(`${agentId}:count`) || 0;

    // O comando Laravel mantém o lock/deduplicação por ID. Aqui controlamos
    // apenas a concorrência: uma instância não processa várias respostas ao
    // mesmo tempo nem abre vários processos PHP simultâneos.
    if (queuedCount >= MAX_PENDING_PER_AGENT) {
        console.warn(`⚠️ [LISTENER] Fila de entrada cheia para ${agentId}; mensagem mantida sem resposta automática.`);
        return;
    }

    processingByAgent.set(`${agentId}:count`, queuedCount + 1);

    const current = previous
        .catch(() => {})
        .then(() => processIncomingMessage(payload, sendResponseCallback));

    processingByAgent.set(agentId, current);

    try {
        await current;
    } finally {
        const remaining = Math.max(0, (processingByAgent.get(`${agentId}:count`) || 1) - 1);
        if (remaining === 0) {
            processingByAgent.delete(`${agentId}:count`);
            if (processingByAgent.get(agentId) === current) {
                processingByAgent.delete(agentId);
            }
        } else {
            processingByAgent.set(`${agentId}:count`, remaining);
        }
    }
}

async function processIncomingMessage(payload, sendResponseCallback) {
    const messageId = payload.whatsapp_message_id
        ? `${payload.agent_id}-${payload.whatsapp_message_id}`
        : `${payload.agent_id}-${payload.from}-${payload.body?.substring(0, 30)}-${payload.timestamp || Date.now()}`;

    let tempFilePath = null;

    try {
        const tempDir = path.resolve(__dirname, '../../../storage/temp');
        await fs.mkdir(tempDir, { recursive: true, mode: 0o700 });
        try {
            await fs.chmod(tempDir, 0o700);
        } catch {
            // O sistema de arquivos pode não suportar chmod; o arquivo ainda
            // será criado com modo restrito abaixo.
        }

        const filename = `payload-${Date.now()}-${Math.random().toString(36).substring(7)}.json`;
        tempFilePath = path.join(tempDir, filename);

        // 1. Salvar payload em JSON temporário
        await fs.writeFile(tempFilePath, JSON.stringify(payload, null, 2), { mode: 0o600 });

        // 2. Localizar comando artisan
        const artisanPath = await getArtisanPath();
        if (!artisanPath) {
            console.error('❌ [LISTENER] Executável artisan do Laravel não encontrado.');
            return sendResponseCallback(payload, null);
        }

        // 3. Executar comando artisan
        const { stdout, stderr } = await execFilePromise(process.env.PHP_BINARY || 'php', [artisanPath, 'whatsapp:process', tempFilePath], {
            timeout: 60000,
            maxBuffer: 2 * 1024 * 1024
        });

        if (stderr && stderr.trim()) {
            console.warn(`⚠️ [LISTENER STDERR]: ${stderr.trim()}`);
        }

        const output = (stdout || '').trim();
        if (!output) {
            return sendResponseCallback(payload, null);
        }

        // 4. Parse da resposta
        const parsed = JSON.parse(output);
        let responseMessage = null;

        if (parsed.status === 'success' && parsed.message !== null && parsed.message !== undefined) {
            responseMessage = parsed.message;

            if (parsed.mediaType && parsed.mediaPath) {
                responseMessage = {
                    mediaType: parsed.mediaType,
                    mediaPath: parsed.mediaPath,
                    mimetype: parsed.mimetype || null,
                    filename: parsed.filename || null,
                    caption: parsed.caption || parsed.message || ''
                };
            }
        }

        const updatedPayload = parsed.payload || payload;
        sendResponseCallback(updatedPayload, responseMessage);

    } catch (error) {
        console.error(`❌ [LISTENER] Erro ao processar mensagem (${messageId}):`, error.message);
        sendResponseCallback(payload, null);
    } finally {
        // 5. Limpeza automática do arquivo temporário para não acumular em disco
        if (tempFilePath) {
            fs.unlink(tempFilePath).catch(() => {});
        }
    }
}

export { handleIncomingMessage };
