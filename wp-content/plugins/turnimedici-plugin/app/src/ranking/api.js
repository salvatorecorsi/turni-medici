const config = window.tmRanking || {};

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

export const getRanking = () => request('ranking');
export const saveRanking = (rules) => request('ranking', { method: 'POST', body: JSON.stringify(rules) });
