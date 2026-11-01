export const QUALIFICATIONS = [
    { value: 'mmg', label: 'MMG' },
    { value: 'ruap_volontario', label: 'RUAP volontario' },
    { value: 'ruap_vincolato', label: 'RUAP vincolato' },
    { value: 'ruap_corsista', label: 'Corsista RUAP' },
    { value: 'ca_titolare', label: 'CA Titolare' },
    { value: 'ca_temporaneo', label: 'CA Temporaneo' },
];

/* ACN 2024 art. 38 c.1 (tabella 4.3 AIR Toscana 2025): orario settimanale RUAP per assistiti in carico */
export const RUAP_WEEKLY_HOURS_BRACKETS = [
    { maxPatients: 400, hours: 38 },
    { maxPatients: 1000, hours: 24 },
    { maxPatients: 1200, hours: 12 },
    { maxPatients: Infinity, hours: 6 },
];

export const ruapWeeklyHoursFromPatients = (patients) =>
    RUAP_WEEKLY_HOURS_BRACKETS.find((bracket) => patients <= bracket.maxPatients).hours;

/* Volontari e CA restano a monte ore 0: la quota oraria ACN riguarda vincolati e corsisti */
export const hasRuapHourlyQuota = (qualification) => qualification === 'ruap_vincolato' || qualification === 'ruap_corsista';

/* Quote corsista: obbligo solo fino a 650 assistiti, oltre restano solo ore facoltative */
export const CORSISTA_WEEKLY_HOURS_BRACKETS = [
    { maxPatients: 650, mandatory: 6, voluntary: 18 },
    { maxPatients: 1000, mandatory: 0, voluntary: 12 },
    { maxPatients: Infinity, mandatory: 0, voluntary: 6 },
];

export const corsistaWeeklyHours = (patients) =>
    CORSISTA_WEEKLY_HOURS_BRACKETS.find((bracket) => patients <= bracket.maxPatients);

/* Massimale ACN per i corsisti con incarico convenzionale temporaneo */
export const CORSISTA_MAX_PATIENTS = 1000;

export const qualLabel = (value) => (QUALIFICATIONS.find((q) => q.value === value) || {}).label || value;
export const isCa = (qualification) => !!qualification && qualification.startsWith('ca_');
export const isRuap = (qualification) => !!qualification && qualification.startsWith('ruap_');
export const isCaTemporaneo = (qualification) => qualification === 'ca_temporaneo';

export function isCaValidToday(doctor) {
    if (doctor.qualification !== 'ca_temporaneo') return true;
    const today = new Date().toISOString().slice(0, 10);
    return (doctor.ca_periods || []).some((period) => period.start && period.end && period.start <= today && today <= period.end);
}
