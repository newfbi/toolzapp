import Echo from 'laravel-echo';
import axios from 'axios';
import Pusher from 'pusher-js';
window.Pusher = Pusher;
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY || 'local',
    wsHost: 'toolzapp.com.br',
    wsPort: 6001, // Alinhar com PUSHER_PORT do .env
    wssPort: 6001, // Alinhar com PUSHER_PORT do .env
    wsPath: '', // Explicitamente remover /app
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
    forceTLS: true, // Alinhar com PUSHER_SCHEME=http no .env
    encrypted: true, // Desativar criptografia para http
    disableStats: true,
    enabledTransports: ['ws'], // Usar apenas ws, já que forceTLS está false
});
