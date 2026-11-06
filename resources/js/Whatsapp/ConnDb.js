import mysql from 'mysql2/promise';
import path from 'path';
import { fileURLToPath } from 'url';
import dotenv from 'dotenv';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
dotenv.config({ path: path.resolve(__dirname, '../../../.env') });

function createDatabasePool() {
    const required = ['DB_HOST', 'DB_USERNAME', 'DB_DATABASE'];
    const missing = required.filter((key) => !process.env[key]);
    if (missing.length > 0) {
        throw new Error(`Configuração de banco incompleta: ${missing.join(', ')}`);
    }

    return mysql.createPool({
        host: process.env.DB_HOST,
        port: parseInt(process.env.DB_PORT || '3306', 10),
        user: process.env.DB_USERNAME,
        database: process.env.DB_DATABASE,
        password: process.env.DB_PASSWORD ?? '',
        waitForConnections: true,
        connectionLimit: 15,
        queueLimit: 0,
        enableKeepAlive: true,
        keepAliveInitialDelay: 10000,
        connectTimeout: 10000
    });
}

let pool = createDatabasePool();

/**
 * Executa queries SQL. Apenas leituras são repetidas automaticamente: em uma
 * escrita, uma queda após o commit não permite saber se repetir causaria
 * duplicidade.
 */
async function queryWithRetry(sql, params = [], maxRetries = 3) {
    let lastError;
    const statement = String(sql).trim().toUpperCase();
    const isReadOnly = /^(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/.test(statement);

    for (let attempt = 1; attempt <= maxRetries; attempt++) {
        try {
            return await pool.query(sql, params);
        } catch (error) {
            lastError = error;

            const isConnectionLost = (
                error.code === 'ECONNRESET' ||
                error.code === 'ENOTFOUND' ||
                error.code === 'ECONNREFUSED' ||
                error.code === 'PROTOCOL_CONNECTION_LOST' ||
                error.code === 'ER_CON_COUNT_ERROR' ||
                error.message.includes('connection is in closed state')
            );

            if (isConnectionLost) {
                if (!isReadOnly) {
                    throw error;
                }

                console.warn(`⚠️ [DB] Leitura ${attempt}/${maxRetries} falhou (${error.code || error.message}). Reconectando pool...`);

                try {
                    await pool.end().catch(() => {});
                    pool = createDatabasePool();
                } catch (reconnectError) {
                    console.error(`❌ [DB] Falha ao recriar pool:`, reconnectError.message);
                }
            }

            if (isConnectionLost && isReadOnly && attempt < maxRetries) {
                const backoff = Math.min(5000, 800 * (2 ** (attempt - 1)));
                await new Promise(resolve => setTimeout(resolve, backoff));
            } else {
                throw error;
            }
        }
    }

    console.error(`❌ [DB] Query falhou após ${maxRetries} tentativas:`, lastError.message);
    throw lastError;
}

export default {
    query: queryWithRetry,

    testConnection: async () => {
        try {
            await queryWithRetry('SELECT 1');
            return true;
        } catch (error) {
            console.error('❌ [DB] Teste de conexão falhou:', error.message);
            return false;
        }
    },

    close: async () => {
        try {
            await pool.end();
            console.log('✅ [DB] Pool encerrado');
        } catch (error) {
            console.error('❌ [DB] Erro ao encerrar pool:', error.message);
        }
    }
};
