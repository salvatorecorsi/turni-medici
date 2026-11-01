export const WEEKDAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

export const WEEKDAY_SHORT = {
    mon: 'Lun',
    tue: 'Mar',
    wed: 'Mer',
    thu: 'Gio',
    fri: 'Ven',
    sat: 'Sab',
    sun: 'Dom',
};

export const MONTH_LABELS = [
    'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno',
    'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre',
];

const pad = (n) => String(n).padStart(2, '0');

export function currentMonth() {
    const now = new Date();
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}`;
}

export function parseMonth(viewMonth) {
    const [year, month] = String(viewMonth).split('-').map(Number);
    return { year, month };
}

export function shiftMonth(viewMonth, delta) {
    const { year, month } = parseMonth(viewMonth);
    const base = new Date(year, month - 1 + delta, 1);
    return `${base.getFullYear()}-${pad(base.getMonth() + 1)}`;
}

export function isoDate(year, month, day) {
    return `${year}-${pad(month)}-${pad(day)}`;
}

export function weekdayKey(year, month, day) {
    const js = new Date(year, month - 1, day).getDay();
    return ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][js];
}

export function monthMatrix(year, month) {
    const daysInMonth = new Date(year, month, 0).getDate();
    const firstWeekday = (new Date(year, month - 1, 1).getDay() + 6) % 7;

    const cells = [];
    for (let i = 0; i < firstWeekday; i++) cells.push(null);
    for (let day = 1; day <= daysInMonth; day++) cells.push(day);
    while (cells.length % 7 !== 0) cells.push(null);

    const weeks = [];
    for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7));
    return weeks;
}

export function effectiveDay(weekKey, override, defaults) {
    if (override) {
        const closed = !!override.closed;
        return {
            open: !closed,
            slots: closed ? [] : (override.slots && override.slots.length ? override.slots : defaults.slots),
            note: override.note || '',
            custom: true,
        };
    }
    const closed = defaults.closedWeekdays.includes(weekKey);
    return { open: !closed, slots: closed ? [] : defaults.slots, note: '', custom: false };
}

const timeToMinutes = (value) => {
    const [hours, minutes] = String(value).split(':').map(Number);
    return (hours * 60) + minutes;
};

const validTime = (value) => {
    const match = String(value || '').match(/^(\d{2}):(\d{2})$/);
    if (!match) return false;
    const hours = Number(match[1]);
    const minutes = Number(match[2]);
    return hours >= 0 && hours <= 23 && minutes >= 0 && minutes <= 59;
};

const slotsOverlap = (a, b) => {
    const startA = timeToMinutes(a.from);
    const endA = timeToMinutes(a.to);
    const startB = timeToMinutes(b.from);
    const endB = timeToMinutes(b.to);
    return Math.max(startA, startB) < Math.min(endA, endB);
};

export function validateSlots(slots, label = 'Fasce orarie') {
    const seen = new Set();
    const valid = [];
    for (let i = 0; i < slots.length; i += 1) {
        const row = i + 1;
        const from = String(slots[i]?.from || '').trim();
        const to = String(slots[i]?.to || '').trim();
        if (!from || !to) return `${label}: compila inizio e fine della fascia ${row}.`;
        if (!validTime(from) || !validTime(to)) return `${label}: formato ora non valido nella fascia ${row}.`;
        if (timeToMinutes(from) >= timeToMinutes(to)) return `${label}: la fascia ${from}–${to} non è valida.`;
        const key = `${from}-${to}`;
        if (seen.has(key)) return `${label}: la fascia ${from}–${to} è duplicata.`;
        const overlap = valid.find((slot) => slotsOverlap({ from, to }, slot));
        if (overlap) return `${label}: la fascia ${from}–${to} si sovrappone a ${overlap.from}–${overlap.to}.`;
        seen.add(key);
        valid.push({ from, to });
    }
    return '';
}
