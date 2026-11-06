module.exports = {
    apps: [
        {
            name: 'toolzapp-worker',
            script: 'artisan',
            args: 'queue:work --sleep=3 --tries=3 --timeout=720 --max-time=3600 --max-jobs=1000', // cobre o job de treinamento de 600s
            interpreter: 'php', // Use PHP interpreter
            cwd: './', // Current working directory
            instances: 1, // Number of instances
            autorestart: true, // Auto restart if crash
            watch: false, // Don't watch files (restart manually if code changes to avoid unexpected restarts during dev)
            max_memory_restart: '500M', // Restart if memory usage exceeds 500MB
            error_file: './storage/logs/toolzapp-error.log', // Log errors
            out_file: './storage/logs/toolzapp-output.log', // Log output
            merge_logs: true,
            env: {
                APP_ENV: 'local',
            },
            env_production: {
                APP_ENV: 'production',
            }
        }
    ]
};
