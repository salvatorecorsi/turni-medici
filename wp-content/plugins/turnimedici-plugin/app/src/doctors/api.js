const config = window.tmDoctors || {};

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
        const error = new Error(data?.message || 'Errore di rete');
        error.code = data?.code || '';
        error.data = data?.data || null;
        throw error;
    }
    return data;
}

export const getDoctors = () => request('doctors');
export const getAfts = () => request('afts');
export const getFacilities = () => request('facilities');
export const createDoctor = (body) => request('doctors', { method: 'POST', body: JSON.stringify(body) });
export const updateDoctor = (id, body) => request(`doctors/${id}`, { method: 'PUT', body: JSON.stringify(body) });
export const getMagicLink = (id) => request(`doctors/${id}/magic-link`);
export const generateMagicLink = (id) => request(`doctors/${id}/magic-link`, { method: 'POST' });
export const sendInvite = (id) => request(`doctors/${id}/invite`, { method: 'POST' });
export const importDoctors = (rows) => request('doctors/import', { method: 'POST', body: JSON.stringify(rows) });
export const getRanking = () => request('ranking');
export const searchEstar = (q) => request(`estar-search?q=${encodeURIComponent(q)}`);
export const importEstar = (estarId, options = {}) => request('estar-import', { method: 'POST', body: JSON.stringify({ estar_id: estarId, ...options }) });
export const refreshEstarDump = () => request('estar-refresh', { method: 'POST' });
export const uploadEstarDump = (dump) => request('estar-upload', { method: 'POST', body: JSON.stringify(dump) });
export const estarStatus = () => request('estar-status');
export const getDoctorActivity = (id) => request(`doctors/${id}/activity`);
export const resolveDuplicateDoctors = (keepId, removeIds) => request('doctors/duplicates/resolve', { method: 'POST', body: JSON.stringify({ keep_id: keepId, remove_ids: removeIds }) });
export const getChangeRequests = () => request('change-requests');
export const resolveChangeRequest = (id, field, action) => request(`change-requests/${id}/resolve`, { method: 'POST', body: JSON.stringify({ field, action }) });
