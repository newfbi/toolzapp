import fs from 'fs';
import { authFolderFor, ensureAuthRoot } from './AuthPaths.js';

class DatabaseAuth {
    constructor(options = {}) {
        this.userId = options.userId;
        this.agentId = options.agentId;
        this.tableInfo = options.tableInfo;
        this.connection = options.connection;
    }

    /**
     * Garante que existe uma instância para agentId
     */
    async ensureInstanceExists() {
        try {
            const [existing] = await this.connection.query(
                `SELECT id, status, phone_number FROM whatsapp_instances WHERE agent_id = ? LIMIT 1`,
                [this.agentId]
            );

            if (!existing || existing.length === 0) {
                console.warn(`⚠️ [${this.agentId}] Instância não encontrada no banco - aguardando criação pelo Laravel`);
                return false;
            }

            await this.connection.query(
                `UPDATE whatsapp_instances SET last_activity = NOW() WHERE agent_id = ?`,
                [this.agentId]
            );

            console.log(`✅ [${this.agentId}] Instância localizada - status atual: ${existing[0].status}`);
            return true;
        } catch (error) {
            console.error(`❌ [${this.agentId}] Erro ao verificar instância:`, error.message);
            throw error;
        }
    }

    /**
     * Atualiza o status da instância
     * STATUS PADRONIZADO: initializing → qr → connecting → connected → disconnected | error
     */
    async updateStatus(status, additionalData = {}) {
        const validStatuses = ['initializing', 'qr', 'connecting', 'connected', 'disconnected', 'error', 'expired', 'corrupted', 'banned'];
        if (!validStatuses.includes(status)) {
            throw new Error(`Status inválido: ${status}. Use: ${validStatuses.join(', ')}`);
        }

        try {
            const updateFields = ['status = ?', 'last_activity = NOW()', 'updated_at = NOW()'];
            const updateValues = [status];

            if (status === 'connected') {
                updateFields.push('connected_at = NOW()', 'error_message = NULL');
            }

            if (additionalData.phoneNumber) {
                updateFields.push('phone_number = ?');
                updateValues.push(additionalData.phoneNumber);
            }

            if (additionalData.qrCode !== undefined) {
                if (additionalData.qrCode === null || additionalData.qrCode === '' || additionalData.qrCode === 'Invalid value.') {
                    updateFields.push('qr_code = NULL');
                } else {
                    updateFields.push('qr_code = ?');
                    updateValues.push(JSON.stringify(additionalData.qrCode));
                }
            }

            if (additionalData.errorMessage) {
                updateFields.push('error_message = ?');
                updateValues.push(additionalData.errorMessage);
            }

            if (additionalData.lastAuthTime) {
                updateFields.push('last_auth_time = NOW()');
            }

            updateValues.push(this.agentId);

            await this.connection.query(
                `UPDATE whatsapp_instances SET ${updateFields.join(', ')} WHERE agent_id = ?`,
                updateValues
            );

            console.log(`📊 [${this.agentId}] Status atualizado: ${status}`);
        } catch (error) {
            console.error(`❌ [${this.agentId}] Erro ao atualizar status:`, error.message);
            throw error;
        }
    }

    /**
     * Reseta a sessão
     */
    async resetSession() {
        try {
            console.log(`🔄 [${this.agentId}] Resetando sessão no banco`);

            await this.connection.query(
                `UPDATE whatsapp_instances
                 SET phone_number = NULL,
                     qr_code = NULL,
                     status = 'disconnected',
                     connected_at = NULL,
                     error_message = NULL,
                     last_activity = NOW(),
                     updated_at = NOW()
                 WHERE agent_id = ?`,
                [this.agentId]
            );

            console.log(`✅ [${this.agentId}] Sessão resetada com sucesso`);
        } catch (error) {
            console.error(`❌ [${this.agentId}] Erro ao resetar sessão:`, error.message);
            throw error;
        }
    }

    /**
     * Limpa arquivos de autenticação da instância
     */
    async deleteInstance() {
        try {
            console.log(`🗑️ [${this.agentId}] Limpando arquivos de autenticação local...`);

            ensureAuthRoot();
            const authFolder = authFolderFor(this.agentId);
            if (fs.existsSync(authFolder)) {
                fs.rmSync(authFolder, { recursive: true, force: true });
                console.log(`✅ [${this.agentId}] Pasta de autenticação removida: ${authFolder}`);
            }

            await this.connection.query(
                `UPDATE whatsapp_instances
                 SET status = 'disconnected',
                     phone_number = NULL,
                     qr_code = NULL,
                     connected_at = NULL,
                     error_message = 'Sessão invalidada - requer novo QR Code',
                     last_activity = NOW(),
                     updated_at = NOW()
                 WHERE agent_id = ?`,
                [this.agentId]
            );

            console.log(`✅ [${this.agentId}] Instância limpa - pronta para novo pareamento`);
        } catch (error) {
            console.error(`❌ [${this.agentId}] Erro ao limpar instância:`, error.message);
            throw error;
        }
    }

    /**
     * Obtém informações da instância
     */
    async getInstance() {
        try {
            const [rows] = await this.connection.query(
                `SELECT * FROM whatsapp_instances WHERE agent_id = ? LIMIT 1`,
                [this.agentId]
            );

            return rows.length > 0 ? rows[0] : null;
        } catch (error) {
            console.error(`❌ [${this.agentId}] Erro ao buscar instância:`, error.message);
            throw error;
        }
    }
}

export default DatabaseAuth;
