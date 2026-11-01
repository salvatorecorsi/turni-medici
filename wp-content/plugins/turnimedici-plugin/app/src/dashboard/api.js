const config = window.tmDashboard || {};

const NONCE_RELOAD_FLAG = 'tmNonceReloaded';

function reloadOnExpiredNonce(data) {
    if (data?.code !== 'rest_cookie_invalid_nonce') return false;
    if (window.sessionStorage?.getItem(NONCE_RELOAD_FLAG)) return false;
    window.sessionStorage?.setItem(NONCE_RELOAD_FLAG, '1');
    window.location.reload();
    return true;
}

function throwRequestError(data) {
    if (reloadOnExpiredNonce(data)) throw new Error('Sessione da aggiornare: ricarico la pagina…');
    if (data?.code === 'rest_cookie_invalid_nonce') throw new Error('Sessione scaduta: ricarica la pagina per continuare.');
    const error = new Error(data?.message || 'Errore di rete');
    error.code = data?.code || '';
    error.data = data?.data || null;
    throw error;
}

async function request(path, options = {}) {
    const response = await fetch(config.restUrl + path, {
        method: options.method || 'GET',
        headers: {
            'X-WP-Nonce': config.nonce,
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: options.body ? JSON.stringify(options.body) : undefined,
    });
    const data = await response.json();
    if (!response.ok) throwRequestError(data);
    window.sessionStorage?.removeItem(NONCE_RELOAD_FLAG);
    return data;
}

export const getMe = () => request('me');
export const getStructures = () => request('me/structures');
const scheduleQuery = (scheduleId) => (scheduleId ? `?schedule=${Number(scheduleId)}` : '');
const boardQuery = (scheduleId, asDoctorId) => {
    const params = new URLSearchParams();
    if (scheduleId) params.set('schedule', Number(scheduleId));
    if (asDoctorId) params.set('as', Number(asDoctorId));
    const query = params.toString();
    return query ? `?${query}` : '';
};
export const getBoard = (id, scheduleId = 0, asDoctorId = 0) => request(`structures/${id}/board${boardQuery(scheduleId, asDoctorId)}`);
export const submitShifts = (id, selections, scheduleId = 0, onBehalfOf = 0) =>
    request(`structures/${id}/shifts${scheduleQuery(scheduleId)}`, { method: 'POST', body: onBehalfOf ? { selections, on_behalf_of: onBehalfOf } : { selections } });
export const getProfile = () => request('me/profile');
export const saveProfile = (body) => request('me/profile', { method: 'POST', body });
export const requestInvite = (id, schedule) => request(`me/structures/${id}/invite-request`, { method: 'POST', body: { schedule } });
export const getInfoPosts = () => request('me/info');
export const getInfoPost = (id) => request(`me/info/${id}`);
export const getScheduleDoctors = (id, scheduleId = 0) => request(`structures/${id}/doctors${scheduleQuery(scheduleId)}`);
export const updateScheduleDoctor = (id, body, scheduleId = 0) => request(`structures/${id}/doctors${scheduleQuery(scheduleId)}`, { method: 'POST', body });
export const confirmScheduleDoctors = (id, scheduleId = 0) => request(`structures/${id}/doctors/confirm${scheduleQuery(scheduleId)}`, { method: 'POST' });
export const setCell = (id, body, scheduleId = 0) => request(`structures/${id}/cell${scheduleQuery(scheduleId)}`, { method: 'POST', body });
export const getCalendar = (id, scheduleId = 0) => request(`structures/${id}/calendar${scheduleQuery(scheduleId)}`);
export const saveCalendar = (id, week, scheduleId = 0, forceRemoveShifts = false) =>
    request(`structures/${id}/calendar${scheduleQuery(scheduleId)}`, { method: 'POST', body: { week, force_remove_shifts: forceRemoveShifts } });
export const createSchedule = (id, body) => request(`structures/${id}/schedules`, { method: 'POST', body });
export const getRankingList = ({ aftId = 0, facilityId = 0, scheduleId = 0 } = {}) => {
    const params = new URLSearchParams();
    if (facilityId) params.set('facility', Number(facilityId));
    else if (aftId) params.set('aft', Number(aftId));
    if (scheduleId) params.set('schedule', Number(scheduleId));
    const query = params.toString();
    return request(`ranking/list${query ? `?${query}` : ''}`);
};
export const getPublicSlotsLink = (facilityId, scheduleId = 0) => request(`structures/${facilityId}/public-slots-link${scheduleId ? `?schedule=${scheduleId}` : ''}`);
export const getRankingBookmark = (key) => request(`ranking/bookmark?key=${encodeURIComponent(key)}`);
export const saveRankingBookmark = (key, doctorId) => request('ranking/bookmark', { method: 'POST', body: { key, doctor_id: doctorId } });
export const getManagedDoctors = () => request('managed-doctors');
export const getLinkMessages = (id) => request(`structures/${id}/link-messages`, { method: 'POST' });
export const ensureDoctorLink = (id, { facilityId = 0, scheduleId = 0 } = {}) => {
    const params = new URLSearchParams();
    if (facilityId) params.set('facility', Number(facilityId));
    if (scheduleId) params.set('schedule', Number(scheduleId));
    const query = params.toString();
    return request(`managed-doctors/${id}/link${query ? `?${query}` : ''}`, { method: 'POST' });
};

async function wpRequest(path) {
    const response = await fetch(config.apiRoot + path, {
        headers: { 'X-WP-Nonce': config.nonce },
    });
    const data = await response.json();
    if (!response.ok) throwRequestError(data);
    window.sessionStorage?.removeItem(NONCE_RELOAD_FLAG);
    return data;
}

export const getGuides = () =>
    wpRequest('wp/v2/tm_guide?per_page=100&orderby=menu_order&order=asc&_fields=id,title,excerpt');
export const getGuide = (id) =>
    wpRequest(`wp/v2/tm_guide/${id}?_fields=id,title,content`);
export const getGlossaryTerms = () =>
    wpRequest('wp/v2/tm_glossary?per_page=100&orderby=title&order=asc&_fields=id,title,content,meta,tm_glossary_group');
export const getGlossaryGroups = () =>
    wpRequest('wp/v2/tm_glossary_group?per_page=100&orderby=name&order=asc&_fields=id,name');
