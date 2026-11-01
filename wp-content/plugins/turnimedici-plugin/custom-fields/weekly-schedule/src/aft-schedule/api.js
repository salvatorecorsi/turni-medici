import apiFetch from '@wordpress/api-fetch';

export const getAllDoctors = () => apiFetch({ path: '/turnimedici/v1/doctors' });
