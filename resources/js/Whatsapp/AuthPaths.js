import fs from 'node:fs';
import path from 'node:path';
import dotenv from 'dotenv';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
dotenv.config({ path: path.join(projectRoot, '.env') });
const configuredRoot = process.env.WHATSAPP_AUTH_DIR;
export const authRoot = configuredRoot
    ? path.resolve(configuredRoot)
    : '/var/lib/toolzapp/whatsapp-auth';

function safeAgentId(agentId) {
    const value = String(agentId || '');
    if (!/^[A-Za-z0-9_-]+$/.test(value)) {
        throw new Error('agentId inválido para diretório de autenticação.');
    }
    return value;
}

export function authFolderFor(agentId) {
    return path.join(authRoot, `instance_${safeAgentId(agentId)}`);
}

export function authBackupFolderFor(agentId) {
    return path.join(authRoot, 'backups', `instance_${safeAgentId(agentId)}`);
}

export function ensureAuthRoot() {
    fs.mkdirSync(authRoot, { recursive: true, mode: 0o700 });
    try {
        fs.chmodSync(authRoot, 0o700);
    } catch {
        // Permissões podem ser gerenciadas pelo sistema operacional/container.
    }
}
