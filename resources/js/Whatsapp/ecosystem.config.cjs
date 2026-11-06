const path = require('path');
require('dotenv').config({ path: path.resolve(__dirname, '../../../.env') });

module.exports = {
    apps: [
        {
            name: 'WhatsServer',
            script: path.resolve(__dirname, 'server.js'),
            cwd: __dirname,
            watch: false,
            instances: 1,
            exec_mode: 'fork',
            env: {
                NODE_ENV: 'production',
                WHATSAPP_BIND_HOST: process.env.WHATSAPP_BIND_HOST || '127.0.0.1',
                WHATSAPP_PORT: process.env.WHATSAPP_PORT || '3200',
                ALLOWED_ORIGINS: process.env.ALLOWED_ORIGINS || 'https://toolzapp.com.br,https://www.toolzapp.com.br',
                WHATSAPP_AUTH_DIR: process.env.WHATSAPP_AUTH_DIR || '/var/lib/toolzapp/whatsapp-auth',
                WHATSAPP_HTTP_TOKEN: process.env.WHATSAPP_HTTP_TOKEN,
                WHATSAPP_SOCKET_SECRET: process.env.WHATSAPP_SOCKET_SECRET,
                WHATSAPP_INTERNAL_URL: process.env.WHATSAPP_INTERNAL_URL || 'http://127.0.0.1:3200',
                MIX_ARTISAN_PATH: path.resolve(__dirname, '../../../artisan') || process.env.MIX_ARTISAN_PATH
            }
        },
        {
            name: 'LaravelQueue',
            script: path.resolve(__dirname, '../../../queue-worker.js'),
            cwd: path.resolve(__dirname, '../../..'),
            watch: false,
            instances: 1,
            exec_mode: 'fork',
            env: {
                NODE_ENV: 'production',
                QUEUE_WORKER_TIMEOUT: process.env.QUEUE_WORKER_TIMEOUT || '720'
            }
        }
    ]
};
