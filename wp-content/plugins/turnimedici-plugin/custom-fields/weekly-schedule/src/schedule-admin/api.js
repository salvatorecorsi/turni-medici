import apiFetch from '@wordpress/api-fetch';

export const getScheduleAdmin = (scheduleId) =>
    apiFetch({ path: `/turnimedici/v1/admin/schedules/${scheduleId}` });

export const saveScheduleAdmin = (scheduleId, payload) =>
    apiFetch({
        path: `/turnimedici/v1/admin/schedules/${scheduleId}`,
        method: 'PUT',
        data: payload,
    });

export const validateScheduleDocument = (scheduleId, document) =>
    apiFetch({
        path: `/turnimedici/v1/admin/schedules/${scheduleId}/validate`,
        method: 'POST',
        data: { document },
    });

export const runScheduleAdminAction = (scheduleId, payload) =>
    apiFetch({
        path: `/turnimedici/v1/admin/schedules/${scheduleId}/action`,
        method: 'POST',
        data: payload,
    });

export const getScheduleSnapshot = (scheduleId, reference) =>
    apiFetch({ path: `/turnimedici/v1/admin/schedules/${scheduleId}/snapshots/${reference}` });
