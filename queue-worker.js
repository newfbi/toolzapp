import { exec } from 'child_process';

console.log('🚀 Iniciando Laravel Queue Worker...');

function startWorker() {
    console.log('📡 [QUEUE] Ouvindo fila de disparos (php artisan queue:work)...');
    
    // O job GenerateAgentTrainingPrompts pode durar até 600s; manter margem
    // para que o worker não mate o processo antes do timeout do próprio job.
    const timeout = process.env.QUEUE_WORKER_TIMEOUT || '720';
    const worker = exec(`php artisan queue:work --sleep=3 --tries=3 --timeout=${timeout} --max-time=3600 --max-jobs=1000`, {
        cwd: process.cwd()
    });

    worker.stdout.on('data', (data) => {
        process.stdout.write(`[QUEUE] ${data}`);
    });

    worker.stderr.on('data', (data) => {
        process.stderr.write(`[QUEUE ERROR] ${data}`);
    });

    worker.on('exit', (code) => {
        console.log(`⚠️ [QUEUE] Worker finalizado (código ${code}). Reiniciando em 2 segundos...`);
        setTimeout(startWorker, 2000);
    });
}

startWorker();
