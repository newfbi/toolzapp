#!/usr/bin/env node

/**
 * Auditoria operacional da integração WhatsApp/Baileys.
 *
 * Este utilitário verifica ambiente, DNS e permissões locais. Não tenta
 * contornar bloqueios, trocar IP, simular comportamento humano ou forçar
 * novos pareamentos. Bloqueios devem ser tratados pelos canais oficiais.
 */

import dotenv from 'dotenv';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import dns from 'node:dns/promises';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
dotenv.config({ path: path.join(projectRoot, '.env') });

console.log('🔍 AUDITORIA OPERACIONAL — WHATSAPP/BAILEYS\n');
console.log(`Node.js: ${process.version}`);
console.log(`Plataforma: ${process.platform} ${process.arch}`);
console.log(`CPUs: ${os.cpus().length}`);
console.log(`Memória total: ${Math.round(os.totalmem() / 1024 / 1024 / 1024)} GB`);

const authDir = process.env.WHATSAPP_AUTH_DIR || '/var/lib/toolzapp/whatsapp-auth';
console.log(`\n📁 Sessões: ${authDir}`);

try {
    const stat = await fs.stat(authDir);
    const mode = stat.mode & 0o777;
    console.log(`   ✅ Diretório disponível (modo ${mode.toString(8)})`);
    if (mode !== 0o700) {
        console.warn('   ⚠️ Recomenda-se restringir o diretório das credenciais para modo 700.');
    }
} catch (error) {
    console.warn(`   ⚠️ Diretório ainda não acessível: ${error.code || error.message}`);
}

console.log('\n🌐 DNS do serviço WhatsApp:');
try {
    const records = await dns.lookup('web.whatsapp.com', { all: true });
    console.log(`   ✅ web.whatsapp.com resolveu (${records.length} endereço(s))`);
} catch (error) {
    console.warn(`   ⚠️ Falha de DNS: ${error.code || error.message}`);
}

console.log('\n⚙️ Configuração do serviço:');
console.log(`   Bind: ${process.env.WHATSAPP_BIND_HOST || '127.0.0.1'}`);
console.log(`   Porta interna: ${process.env.WHATSAPP_PORT || '3200'}`);
console.log(`   URL interna: ${process.env.WHATSAPP_INTERNAL_URL || 'http://127.0.0.1:3200'}`);
console.log(`   Token HTTP: ${process.env.WHATSAPP_HTTP_TOKEN ? 'configurado' : 'AUSENTE'}`);
console.log(`   Segredo Socket.IO: ${process.env.WHATSAPP_SOCKET_SECRET ? 'configurado' : 'AUSENTE'}`);

console.log('\n✅ Práticas obrigatórias de operação:');
console.log('   • usar somente contatos com consentimento e respeitar opt-out/STOP;');
console.log('   • manter identidade comercial clara e conteúdo esperado pelo contato;');
console.log('   • manter uma única sessão ativa por número e preservar as credenciais;');
console.log('   • interromper envios diante de erro de sessão, bloqueio ou reclamações;');
console.log('   • não usar VPN, rotação de IP, troca de número ou reset de QR para contornar bloqueios.');

console.log('\nℹ️ Baileys é uma integração não oficial e não oferece garantia contra bloqueios. Para volume elevado ou mensagens iniciadas pela empresa, prefira a WhatsApp Business Platform/Cloud API oficial.');
