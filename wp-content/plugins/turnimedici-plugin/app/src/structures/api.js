const config = window.tmStructures || {};

async function request(path, options = {}) {
    const response = await fetch(config.restUrl + path, {
        ...options,
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': config.nonce,
            ...(options.headers || {}),
        },
    });
    const data = await response.json();
    if (!response.ok) {
        throw new Error(data?.message || 'Errore di rete');
    }
    return data;
}

export const getOverview = () => request('schedules-overview');
