import { useState, useEffect, useRef } from '@wordpress/element';
import { getBoard, submitShifts, requestInvite, getScheduleDoctors, updateScheduleDoctor, confirmScheduleDoctors, setCell, getCalendar, saveCalendar, createSchedule } from './api';
import { CalendarSettingsModal, DayOverrideModal } from './calendar-editor';
import ShiftSummaryModal from './shift-summary-modal';

const MONTHS = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

const slotHours = (key) => {
    const [a, b] = key.split('-');
    const min = (t) => {
        const [h, m] = t.split(':');
        return Number(h) * 60 + Number(m);
    };
    return Math.max(0, (min(b) - min(a)) / 60);
};

const rangeLabel = (key) => key.replace('-', ' – ');
const normalizeText = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const pad = (n) => String(n).padStart(2, '0');
const monthText = (month) => (month ? `${MONTHS[(month.month || 1) - 1]} ${month.year}` : '');
const monthValue = (month) => (month ? `${month.year}-${pad(month.month || 1)}` : '');
const nextMonthValue = (months = []) => {
    const first = months[0]?.month;
    if (!first) {
        const now = new Date();
        return `${now.getFullYear()}-${pad(now.getMonth() + 1)}`;
    }
    const date = new Date(Number(first.year), Number(first.month), 1);
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;
};

const initialScheduleId = () => {
    const search = new URLSearchParams(window.location.search);
    const fromSearch = Number(search.get('schedule')) || 0;
    if (fromSearch) return fromSearch;

    const hashQuery = window.location.hash.includes('?') ? window.location.hash.split('?')[1] : '';
    const hash = new URLSearchParams(hashQuery);
    return Number(hash.get('schedule')) || 0;
};

const findRosterDoctor = (doctors, doctorId) => {
    const rows = [...(doctors?.assigned || []), ...(doctors?.others || [])];
    return rows.find((doctor) => Number(doctor.doctor_id) === Number(doctorId)) || null;
};

const rosterIds = (doctors) => (doctors?.assigned || []).map((doctor) => Number(doctor.doctor_id)).filter(Boolean);

const rosterSignature = (doctors) => (doctors?.assigned || [])
    .map((doctor) => `${Number(doctor.doctor_id)}:${Number(doctor.required_hours) || 0}`)
    .join(',');

const rosterHasSameValues = (a, b) => rosterSignature(a) === rosterSignature(b);

const rosterRequiredHours = (doctors) => Object.fromEntries(
    (doctors?.assigned || []).map((doctor) => [Number(doctor.doctor_id), Number(doctor.required_hours) || 0])
);

const withRosterPositions = (doctors) => ({
    ...doctors,
    assigned: (doctors?.assigned || []).map((doctor, index) => ({ ...doctor, position: index + 1 })),
    others: [...(doctors?.others || [])],
});

const compareRosterDoctors = (a, b) => {
    const positionA = Number(a.position) || 999999;
    const positionB = Number(b.position) || 999999;
    if (positionA !== positionB) return positionA - positionB;
    return String(a.name || '').localeCompare(String(b.name || ''), 'it', { sensitivity: 'base' });
};

const rosterNotificationText = (notification, doctors) => {
    if (!notification) return '';
    const doctor = findRosterDoctor(doctors, notification.doctor_id);
    const label = doctor?.name || notification.email || '';

    if (notification.sent) {
        return `Email inviata${label ? ` a ${label}` : ''}.`;
    }
    if (notification.reason === 'already_notified') {
        return `Lista confermata: ${label || 'il medico di turno'} era già stato avvisato.`;
    }
    if (notification.reason === 'already_confirmed') {
        return 'Lista già confermata.';
    }
    if (notification.reason === 'no_current') {
        return 'Lista confermata: nessun medico in attesa da avvisare.';
    }
    return 'Lista confermata.';
};

const CalIcon = () => (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" /></svg>
);
const SunIcon = () => (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" /></svg>
);
const DuskIcon = () => (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M17 18a5 5 0 0 0-10 0" /><line x1="12" y1="2" x2="12" y2="9" /><line x1="4.2" y1="10.2" x2="5.6" y2="11.6" /><line x1="1" y1="18" x2="3" y2="18" /><line x1="21" y1="18" x2="23" y2="18" /><line x1="18.4" y1="11.6" x2="19.8" y2="10.2" /><line x1="23" y1="22" x2="1" y2="22" /><polyline points="8 6 12 10 16 6" /></svg>
);
const LockIcon = () => (
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
);
const ClockIcon = () => (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><polyline points="12 7 12 12 15 14" /></svg>
);

function NewScheduleModal({ defaultMonth, saving, onCreate, onClose }) {
    const [month, setMonth] = useState(defaultMonth);
    const [setDefault, setSetDefault] = useState(false);

    return (
        <div className="tmp-overlay" onClick={onClose}>
            <div className="tmp-modal tmp-modal--sm" onClick={(e) => e.stopPropagation()}>
                <div className="tmp-modal__head">
                    <h2>Nuovo orario</h2>
                    <button type="button" className="tmp-modal__close" onClick={onClose} aria-label="Chiudi">×</button>
                </div>
                <div className="tmp-modal__body">
                    <label className="tmp-field">
                        <span>Mese</span>
                        <input type="month" value={month} onChange={(e) => setMonth(e.target.value)} />
                    </label>
                    <label className="tmc-toggle tmc-toggle--roomy">
                        <input type="checkbox" checked={setDefault} onChange={(e) => setSetDefault(e.target.checked)} />
                        Mostra come predefinito
                    </label>
                </div>
                <div className="tmp-modal__foot">
                    <button type="button" className="tmp-btn tmp-btn--ghost" onClick={onClose}>Annulla</button>
                    <button type="button" className="tmp-btn tmp-btn--primary" disabled={saving || !month} onClick={() => onCreate({ month, set_default: setDefault })}>
                        {saving ? 'Creazione…' : 'Crea orario'}
                    </button>
                </div>
            </div>
        </div>
    );
}

export default function StructureView({ facilityId, onTitle, onManagementScope, onScheduleChange, impersonateOpen, onImpersonateClose }) {
    const [board, setBoard] = useState(null);
    const [error, setError] = useState(null);
    const [selections, setSelections] = useState({});
    const [saving, setSaving] = useState(false);
    const [summaryOpen, setSummaryOpen] = useState(false);
    const [submitError, setSubmitError] = useState(null);
    const [scheduleId, setScheduleId] = useState(initialScheduleId);
    const [actingAs, setActingAs] = useState(null);
    const [imperDocs, setImperDocs] = useState(null);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [monthPickerOpen, setMonthPickerOpen] = useState(false);
    const [inviteSaving, setInviteSaving] = useState(false);
    const [inviteError, setInviteError] = useState(null);
    const [ctx, setCtx] = useState(null);
    const [assign, setAssign] = useState(null);
    const [docs, setDocs] = useState(null);
    const [cellBusy, setCellBusy] = useState(false);
    const [docQuery, setDocQuery] = useState('');
    const [week, setWeek] = useState(null);
    const [dayMenu, setDayMenu] = useState(null);
    const [calOpen, setCalOpen] = useState(false);
    const [dayEdit, setDayEdit] = useState(null);
    const [calSaving, setCalSaving] = useState(false);
    const [newScheduleOpen, setNewScheduleOpen] = useState(false);
    const [newScheduleSaving, setNewScheduleSaving] = useState(false);
    const [rosterOpen, setRosterOpen] = useState(false);
    const [rosterDocs, setRosterDocs] = useState(null);
    const [rosterDraft, setRosterDraft] = useState(null);
    const [rosterBusy, setRosterBusy] = useState(false);
    const [rosterQuery, setRosterQuery] = useState('');
    const [rosterView, setRosterView] = useState('selected');
    const [rosterNotice, setRosterNotice] = useState('');
    const tapRef = useRef({ key: null, t: 0 });
    const config = window.tmDashboard || {};

    useEffect(() => {
        setBoard(null);
        setError(null);
        setSummaryOpen(false);
        setSubmitError(null);
        getBoard(facilityId, scheduleId, actingAs?.id || 0).then((data) => {
            setBoard(data);
            if (onTitle) onTitle(data.structure.name);
        }).catch((err) => setError(err.message));
    }, [facilityId, scheduleId, actingAs]);


    useEffect(() => {
        if (!impersonateOpen) {
            setImperDocs(null);
            return;
        }
        getScheduleDoctors(facilityId, scheduleId).then(setImperDocs).catch((err) => {
            window.alert(err.message);
            if (onImpersonateClose) onImpersonateClose();
        });
    }, [impersonateOpen, facilityId, scheduleId]);

    const canManage = !!board?.can_manage;
    const hasSchedule = !!board?.schedule;
    const activeScheduleId = board?.schedule?.id || scheduleId || 0;

    useEffect(() => {
        if (!activeScheduleId) return;
        const url = new URL(window.location.href);
        if (Number(url.searchParams.get('schedule')) === activeScheduleId) return;
        url.searchParams.set('schedule', activeScheduleId);
        window.history.replaceState(window.history.state, '', url.toString());
    }, [activeScheduleId]);

    useEffect(() => {
        if (onManagementScope) onManagementScope(canManage);
    }, [canManage, onManagementScope]);

    useEffect(() => {
        if (onScheduleChange) onScheduleChange(activeScheduleId);
    }, [activeScheduleId, onScheduleChange]);

    useEffect(() => {
        if (canManage) return;
        setCtx(null);
        setAssign(null);
        setDocs(null);
        setDocQuery('');
        setDayMenu(null);
        setCalOpen(false);
        setDayEdit(null);
        setWeek(null);
        setNewScheduleOpen(false);
        setRosterOpen(false);
        setRosterDocs(null);
        setRosterDraft(null);
        setRosterQuery('');
        setRosterNotice('');
    }, [canManage]);

    useEffect(() => {
        if (!canManage || !hasSchedule) return;
        getCalendar(facilityId, activeScheduleId).then((calendar) => setWeek(calendar.week)).catch(() => setWeek(null));
    }, [facilityId, activeScheduleId, canManage, hasSchedule]);

    useEffect(() => {
        const closeOnEscape = (event) => {
            if (event.key !== 'Escape') return;
            setCtx(null);
            setDayMenu(null);
            setAssign(null);
            setCalOpen(false);
            setDayEdit(null);
            setNewScheduleOpen(false);
            setInviteOpen(false);
            setDocs(null);
            setDocQuery('');
            setRosterOpen(false);
            setRosterDocs(null);
            setRosterDraft(null);
            setRosterQuery('');
            setRosterNotice('');
        };
        window.addEventListener('keydown', closeOnEscape);
        return () => window.removeEventListener('keydown', closeOnEscape);
    }, []);

    if (error) return <div className="tmd-error">{error}</div>;
    if (!board) return <div className="tmd-loading">Caricamento…</div>;

    const me = board.me;
    const canEdit = board.invited && me && me.can_compile;
    const canRequest = !board.invited && !board.requested && !!board.schedule;
    const selectedHours = Object.values(selections).flat().reduce((sum, key) => sum + slotHours(key), 0);
    const coveredHours = (Number(me?.done_hours) || 0) + selectedHours;
    const slotRange = (key) => {
        const [from, to] = String(key).split('-');
        const minutes = (time) => {
            const [hours, mins] = String(time || '').split(':').map(Number);
            return (hours || 0) * 60 + (mins || 0);
        };
        const start = minutes(from);
        let end = minutes(to);
        if (end <= start) end += 24 * 60;
        return [start, end];
    };
    const slotsOverlap = (a, b) => {
        const [aStart, aEnd] = slotRange(a);
        const [bStart, bEnd] = slotRange(b);
        return Math.max(0, Math.min(aEnd, bEnd) - Math.max(aStart, bStart));
    };
    const shortTime = (time) => (String(time).endsWith(':00') ? String(time).slice(0, 2).replace(/^0/, '') : time);
    const shortRange = (key) => {
        const [from, to] = String(key).split('-');
        return `${shortTime(from)}–${shortTime(to)}`;
    };

    const monthSlotKeys = [...new Set(board.days.filter((d) => d.open).flatMap((d) => d.slots.map((s) => s.key)))];
    const baseColumns = (board.base_slots || []).filter(Boolean);
    const columnFor = {};
    monthSlotKeys.forEach((key) => {
        if (baseColumns.includes(key)) {
            columnFor[key] = key;
            return;
        }
        let bestColumn = key;
        let bestShared = 0;
        baseColumns.forEach((base) => {
            const shared = slotsOverlap(key, base);
            if (shared > bestShared) {
                bestColumn = base;
                bestShared = shared;
            }
        });
        columnFor[key] = bestColumn;
    });
    const columns = [...new Set(Object.values(columnFor))].sort((a, b) => slotRange(a)[0] - slotRange(b)[0] || slotRange(a)[1] - slotRange(b)[1]);
    const month = board.schedule ? board.schedule.month : null;
    const monthLabel = monthText(month);
    const defaultNewMonth = nextMonthValue(board.months || []);

    const mandatory = !!(me && me.is_mandatory);
    const required = me ? me.required_hours : 0;
    const canSkip = !mandatory && coveredHours <= 0;
    const canSubmit = mandatory ? coveredHours > 0 : true;
    const barText = mandatory && required > 0
        ? `${coveredHours}/${required}h`
        : (coveredHours > 0 ? `${coveredHours}h coperte` : (mandatory ? 'Nessun turno selezionato' : 'Salta senza turni'));

    const toggle = (date, key) => {
        setSelections((prev) => {
            const day = prev[date] || [];
            const next = { ...prev };
            if (day.includes(key)) {
                const filtered = day.filter((k) => k !== key);
                if (filtered.length) next[date] = filtered;
                else delete next[date];
            } else {
                next[date] = [...day, key];
            }
            return next;
        });
    };

    const submit = async () => {
        if (!canSubmit || saving) return;
        if (canSkip) {
            const message = actingAs
                ? `Saltare il turno di ${actingAs.name} senza selezionare disponibilità?`
                : 'Saltare il turno senza selezionare disponibilità?';
            if (!window.confirm(message)) return;
        }
        setSubmitError(null);
        setSaving(true);
        try {
            const updated = await submitShifts(facilityId, selections, activeScheduleId, actingAs?.id || 0);
            setSummaryOpen(false);
            if (actingAs) {
                setActingAs(null);
                setSelections({});
            } else {
                setBoard(updated);
                setSelections({});
            }
        } catch (err) {
            if (summaryOpen) setSubmitError(err.message);
            else window.alert(err.message);
        } finally {
            setSaving(false);
        }
    };

    const sendInvite = async () => {
        setInviteSaving(true);
        setInviteError(null);
        try {
            const updated = await requestInvite(facilityId, board.schedule.id);
            setBoard(updated);
            setInviteOpen(false);
        } catch (err) {
            setInviteError(err.message);
        } finally {
            setInviteSaving(false);
        }
    };

    const openCtx = (e, date, slot, taken, name) => {
        if (!canManage) return;
        e.preventDefault();
        const px = e.clientX || (e.touches && e.touches[0] ? e.touches[0].clientX : 0);
        const py = e.clientY || (e.touches && e.touches[0] ? e.touches[0].clientY : 0);
        const x = Math.max(8, Math.min(px, window.innerWidth - 220));
        const y = Math.max(8, Math.min(py, window.innerHeight - 150));
        setCtx({ date, slot, taken, name, x, y });
    };

    const adminDoubleTap = (e, date, slot, taken, name) => {
        if (!canManage) return false;
        const key = `${date}|${slot}`;
        const now = Date.now();
        if (tapRef.current.key === key && now - tapRef.current.t < 350) {
            tapRef.current = { key: null, t: 0 };
            openCtx(e, date, slot, taken, name);
            return true;
        }
        tapRef.current = { key, t: now };
        return false;
    };

    const applyCell = async (date, slot, doctorId) => {
        if (!canManage) return;
        setCellBusy(true);
        try {
            const updated = await setCell(facilityId, { date, slot, doctor_id: doctorId }, activeScheduleId);
            setBoard(updated);
            setCtx(null);
            setAssign(null);
            setDocs(null);
        } catch (err) {
            window.alert(err.message);
        } finally {
            setCellBusy(false);
        }
    };
    const clearCell = (date, slot) => {
        if (!window.confirm('Eliminare questo turno?')) return;
        applyCell(date, slot, 0);
    };

    const openAssign = (cell) => {
        if (!canManage) return;
        setAssign({ date: cell.date, slot: cell.slot });
        setCtx(null);
        setDocs(null);
        setDocQuery('');
        getScheduleDoctors(facilityId, activeScheduleId).then(setDocs).catch((err) => window.alert(err.message));
    };

    const pickDoctor = (d, needsConfirm) => {
        if (needsConfirm && !window.confirm('Il medico non è presente nel turno, aggiungerlo?')) return;
        applyCell(assign.date, assign.slot, d.doctor_id);
    };

    const closeAssign = () => { setAssign(null); setDocs(null); };
    const assignDay = assign ? board.days.find((d) => d.date === assign.date) : null;
    const loadRoster = () => {
        if (!canManage) return;
        getScheduleDoctors(facilityId, activeScheduleId).then((payload) => {
            setRosterDocs(payload);
            setRosterDraft(payload);
        }).catch((err) => window.alert(err.message));
    };
    const openRoster = () => {
        if (!canManage) return;
        setRosterOpen(true);
        setRosterDocs(null);
        setRosterDraft(null);
        setRosterQuery('');
        setRosterView('selected');
        setRosterNotice('');
        loadRoster();
    };
    const closeRoster = () => {
        setRosterOpen(false);
        setRosterDocs(null);
        setRosterDraft(null);
        setRosterQuery('');
        setRosterNotice('');
    };
    const changeRosterDoctor = (doctorId, action) => {
        if (!canManage) return;
        const doctor = findRosterDoctor(rosterDraft, doctorId);
        if (!doctor) return;
        if (action === 'remove') {
            if (doctor.has_shifts) {
                window.alert('Il medico ha già turni assegnati: rimuovi prima i turni.');
                return;
            }
            if (!window.confirm('Rimuovere il medico da questo turno?')) return;
        }
        setRosterDraft((current) => {
            if (!current) return current;
            if (action === 'add') {
                return withRosterPositions({
                    ...current,
                    assigned: [...current.assigned, doctor],
                    others: current.others.filter((item) => Number(item.doctor_id) !== Number(doctorId)),
                    confirmed: false,
                });
            }
            if (action === 'remove') {
                return withRosterPositions({
                    ...current,
                    assigned: current.assigned.filter((item) => Number(item.doctor_id) !== Number(doctorId)),
                    others: [...current.others, doctor].sort(compareRosterDoctors),
                    confirmed: false,
                });
            }
            return current;
        });
        setRosterNotice('');
    };
    const changeRosterRequiredHours = (doctorId, value) => {
        const requiredHours = Math.max(0, Number(value) || 0);
        setRosterDraft((current) => current ? {
            ...current,
            assigned: current.assigned.map((doctor) => (
                Number(doctor.doctor_id) === Number(doctorId)
                    ? { ...doctor, required_hours: requiredHours }
                    : doctor
            )),
            confirmed: false,
        } : current);
        setRosterNotice('');
    };
    const syncRosterDraft = async () => {
        if (!rosterDraft || rosterHasSameValues(rosterDocs, rosterDraft)) return rosterDocs;
        const result = await updateScheduleDoctor(facilityId, {
            action: 'set',
            doctor_ids: rosterIds(rosterDraft),
            required_hours: rosterRequiredHours(rosterDraft),
        }, activeScheduleId);
        setRosterDocs(result.doctors);
        setRosterDraft(result.doctors);
        setBoard(result.board);
        return result.doctors;
    };
    const confirmRoster = async () => {
        if (!canManage || !rosterDraft) return;
        if (!window.confirm("Confermare partecipanti e inviare l'email al medico di turno?")) return;
        setRosterBusy(true);
        try {
            await syncRosterDraft();
            const result = await confirmScheduleDoctors(facilityId, activeScheduleId);
            setRosterDocs(result.doctors);
            setRosterDraft(result.doctors);
            setBoard(result.board);
            setRosterNotice(rosterNotificationText(result.notification, result.doctors));
        } catch (err) {
            window.alert(err.message);
        } finally {
            setRosterBusy(false);
        }
    };

    const saveWeek = async (nextWeek, forceRemoveShifts = false) => {
        if (!canManage) return;
        setCalSaving(true);
        try {
            const updated = await saveCalendar(facilityId, nextWeek, activeScheduleId, forceRemoveShifts);
            setBoard(updated);
            setWeek(nextWeek);
            setCalOpen(false);
            setDayEdit(null);
            setDayMenu(null);
            setCtx(null);
        } catch (err) {
            if (err.code === 'tm_orphaned_shifts' && !forceRemoveShifts) {
                setCalSaving(false);
                if (window.confirm(`${err.message}\n\nVuoi rimuovere quei turni assegnati e salvare comunque?`)) {
                    saveWeek(nextWeek, true);
                }
                return;
            }
            window.alert(err.message);
        } finally {
            setCalSaving(false);
        }
    };

    const createNewSchedule = async (body) => {
        if (!canManage) return;
        setNewScheduleSaving(true);
        try {
            const result = await createSchedule(facilityId, body);
            setScheduleId(result.schedule.id);
            setBoard(result.board);
            setWeek(result.calendar?.week || null);
            setSelections({});
            setNewScheduleOpen(false);
        } catch (err) {
            window.alert(err.message);
        } finally {
            setNewScheduleSaving(false);
        }
    };

    const openDayMenu = (e, day) => {
        if (!canManage) return;
        e.preventDefault();
        const px = e.clientX || (e.touches && e.touches[0] ? e.touches[0].clientX : 0);
        const py = e.clientY || (e.touches && e.touches[0] ? e.touches[0].clientY : 0);
        const x = Math.max(8, Math.min(px, window.innerWidth - 220));
        const y = Math.max(8, Math.min(py, window.innerHeight - 190));
        setDayMenu({ iso: day.date, label: `${day.day} ${monthLabel}`, open: day.open, x, y });
    };

    const toggleDayClosed = (iso, closeIt) => {
        if (!canManage || !week) return;
        const overrides = { ...(week.overrides || {}) };
        const previous = overrides[iso] || {};
        overrides[iso] = { ...previous, closed: closeIt };
        saveWeek({ ...week, overrides });
    };

    const status = (() => {
        if (!board.invited) {
            if (board.requested) return <span className="tmv-pill-status tmv-pill-status--wait">Richiesta inviata</span>;
            if (canManage) return null;
            return <span className="tmv-pill-status tmv-pill-status--ro">Sola consultazione</span>;
        }
        if (me.status === 'skipped') return <span className="tmv-pill-status tmv-pill-status--done">Turno saltato</span>;
        if (me.status === 'done') return <span className="tmv-pill-status tmv-pill-status--done">Turni confermati · {me.done_hours}h</span>;
        if (!me.participants_confirmed) return <span className="tmv-pill-status tmv-pill-status--wait">Lista non confermata</span>;
        if (!me.can_compile) return <span className="tmv-pill-status tmv-pill-status--wait">{me.waiting_for ? 'In attesa di ' + me.waiting_for : 'In attesa'}</span>;
        return null;
    })();
    const rosterAssignedCount = rosterDraft?.assigned?.length || 0;
    const rosterDirty = !!(rosterDocs && rosterDraft && !rosterHasSameValues(rosterDocs, rosterDraft));
    const rosterConfirmed = !!rosterDocs?.confirmed && !rosterDirty;
    const orphanedShifts = Array.isArray(board.orphaned_shifts) ? board.orphaned_shifts : [];
    const orphanedCount = orphanedShifts.reduce((sum, item) => sum + Number(item.count || 0), 0);
    const orphanedSlots = orphanedShifts.map((item) => rangeLabel(item.slot || '')).join(', ');

    return (
        <div className="tmv">
            <div className="tmv-structhead">
                <a className="tmv-backbtn" href={`${config.homeUrl || '/'}#/dashboard`} title="Torna alle sedi" aria-label="Torna alle sedi">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
                </a>
                <h1 className="tmv-structure-title">{board.structure.name}</h1>
                {status}
            </div>
            <div className="tmv-topline">
                <div className="tmv-head__actions">
                    {canManage && (
                        <button type="button" className="tmv-actionbtn" onClick={() => setNewScheduleOpen(true)}>
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
                            <span className="tmv-actionbtn__full">Aggiungi mese</span>
                            <span className="tmv-actionbtn__short">Mese</span>
                        </button>
                    )}
                    {canManage && board.schedule && (
                        <button type="button" className="tmv-actionbtn tmv-actionbtn--ghost" onClick={openRoster}>
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" aria-hidden="true"><line x1="9" y1="6" x2="20" y2="6" /><line x1="9" y1="12" x2="20" y2="12" /><line x1="9" y1="18" x2="20" y2="18" /><circle cx="4.5" cy="6" r="0.5" /><circle cx="4.5" cy="12" r="0.5" /><circle cx="4.5" cy="18" r="0.5" /></svg>
                            <span className="tmv-actionbtn__full">Lista turno</span>
                            <span className="tmv-actionbtn__short">Lista</span>
                        </button>
                    )}
                    {canManage && board.schedule && week && (
                        <button type="button" className="tmv-actionbtn tmv-actionbtn--ghost" onClick={() => setCalOpen(true)}>
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M12 20h9" /><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" /></svg>
                            <span className="tmv-actionbtn__full">Modifica mese</span>
                            <span className="tmv-actionbtn__short">Modifica</span>
                        </button>
                    )}
                </div>
            </div>

            {actingAs && (
                <div className="tmv-actingbar" role="status">
                    <svg viewBox="0 -960 960 960" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M609-389q-29-29-29-71t29-71q29-29 71-29t71 29q29 29 29 71t-29 71q-29 29-71 29t-71-29ZM480-160v-56q0-24 12.5-44.5T528-290q36-15 74.5-22.5T680-320q39 0 77.5 7.5T832-290q23 9 35.5 29.5T880-216v56H480ZM287-527q-47-47-47-113t47-113q47-47 113-47t113 47q47 47 47 113t-47 113q-47 47-113 47t-113-47ZM80-160v-112q0-34 17-62.5t47-43.5q60-30 124.5-46T400-440q35 0 70 6t70 14l-34 34-34 34q-18-5-36-6.5t-36-1.5q-58 0-113.5 14T180-306q-10 5-15 14t-5 20v32h240v80H80Z" /></svg>
                    <span>Stai compilando al posto di <strong>{actingAs.name}</strong></span>
                    <button type="button" onClick={() => { setActingAs(null); setSelections({}); }}>Esci</button>
                </div>
            )}

            <div className="tmv-card">
                <div className="tmv-calhead">
                    <div className="tmv-calhead__left">
                        {(canManage ? (board.months || []).length > 0 : (board.months || []).length > 1) ? (
                            <button
                                type="button"
                                className="tmv-calhead__month tmv-calhead__month--picker"
                                onClick={() => setMonthPickerOpen(true)}
                                aria-label="Cambia mese"
                            >
                                <CalIcon /> {monthLabel || 'Nessun orario'}
                                <svg className="tmv-calhead__chev" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9" /></svg>
                            </button>
                        ) : (
                            <span className="tmv-calhead__month"><CalIcon /> {monthLabel || 'Nessun orario'}</span>
                        )}
                    </div>
                    <div className="tmv-legend">
                        <span><i className="tmv-dot tmv-dot--free" /> Libero</span>
                        <span><i className="tmv-dot tmv-dot--mine" /> I tuoi</span>
                        <span><i className="tmv-dot tmv-dot--taken" /> Occupato</span>
                        <span><i className="tmv-dot tmv-dot--closed" /> Chiuso</span>
                    </div>
                </div>

                {canRequest && !canManage && (
                    <p className="tmv-requesthint">Non sei in turnazione qui: tocca uno slot libero per chiedere di essere invitato.</p>
                )}

                {canManage && orphanedShifts.length > 0 && (
                    <p className="tmv-warning">
                        Ci sono {orphanedCount} turni assegnati su fasce non più presenti nel calendario: {orphanedSlots}. Ripristina quelle fasce o rimuovi prima i turni.
                    </p>
                )}

                {!board.schedule ? (
                    <div className="tmv-empty-schedule">
                        <p>Nessun orario per questa struttura.</p>
                        {canManage && (
                            <button type="button" className="tmp-btn tmp-btn--primary" onClick={() => setNewScheduleOpen(true)}>Crea orario</button>
                        )}
                    </div>
                ) : (
                <div className="tmv-gridwrap">
                    <div className="tmv-grid" style={{ gridTemplateColumns: `minmax(96px, auto) repeat(${columns.length}, minmax(150px, 1fr))` }}>
                        <div className="tmv-grid__corner">GIORNO</div>
                        {columns.map((col) => (
                            <div key={col} className="tmv-grid__colhead">
                                {Number(col.split(':')[0]) < 14 ? <SunIcon /> : <DuskIcon />}
                            </div>
                        ))}

                        {board.days.map((day, dayIndex) => (
                            <div key={day.date} className="tmv-dayrow">
                                <div
                                    className={`tmv-grid__rowhead${day.open ? '' : ' is-closed'}${canManage && week ? ' is-admin' : ''}`}
                                    onContextMenu={canManage && week ? (e) => openDayMenu(e, day) : undefined}
                                    onClick={canManage && week ? (e) => openDayMenu(e, day) : undefined}
                                >
                                    <CalIcon />
                                    <strong>{day.day}</strong>
                                    <span>{day.weekday}</span>
                                    <span className="tmv-rowhead__full">{day.weekday} {day.day} {monthLabel}</span>
                                    {day.open && day.note ? <span className="tmv-rowhead__note" title={day.note}>{day.note}</span> : null}
                                </div>
                                {!day.open ? (
                                    <div
                                        className={`tmv-grid__closed${canManage && week ? ' is-admin' : ''}`}
                                        style={{ gridColumn: '2 / -1' }}
                                        onContextMenu={canManage && week ? (e) => openDayMenu(e, day) : undefined}
                                        onClick={canManage && week ? (e) => openDayMenu(e, day) : undefined}
                                    >
                                        <LockIcon /> Chiuso{day.note ? <span className="tmv-grid__closed-note">· {day.note}</span> : null}{canManage && week && <span className="tmv-grid__closed-hint">· tocca per riaprire</span>}
                                    </div>
                                ) : (
                                    columns.map((col, i) => {
                                        const cellSlots = day.slots.filter((s) => columnFor[s.key] === col);
                                        if (!cellSlots.length) {
                                            return <div key={col} className="tmv-grid__cell is-na" />;
                                        }
                                        const isSplit = cellSlots.length > 1;
                                        const cells = cellSlots.map((slot) => {
                                            const label = isSplit ? shortRange(slot.key) : rangeLabel(slot.key);
                                            if (slot.taken) {
                                                return (
                                                    <div
                                                        key={slot.key}
                                                        className={`tmv-grid__cell is-taken${slot.mine ? ' is-mine' : ''}${canManage ? ' is-admin' : ''}`}
                                                        onContextMenu={canManage ? (e) => openCtx(e, day.date, slot.key, true, slot.taken_by) : undefined}
                                                        onClick={canManage ? (e) => openCtx(e, day.date, slot.key, true, slot.taken_by) : undefined}
                                                    >
                                                        <span className="tmv-cell__name">{(board.invited || canManage) ? (slot.taken_by || 'Occupato') : 'Occupato'}</span>
                                                        <span className="tmv-cell__range">{label}</span>
                                                    </div>
                                                );
                                            }
                                            const selected = board.invited && (selections[day.date] || []).includes(slot.key);
                                            const unavailable = !!slot.unavailable && !selected;
                                            const unavailableText = slot.unavailable_facility
                                                ? `Hai già un turno a ${slot.unavailable_facility}${slot.unavailable_slot ? ` · ${rangeLabel(slot.unavailable_slot)}` : ''}`
                                                : 'Hai già un turno sovrapposto';
                                            let selStyle;
                                            if (selected) {
                                                if (isSplit || slot.key !== col) {
                                                    selStyle = { boxShadow: 'inset 0 0 0 2px #b6e0c8' };
                                                } else {
                                                    const isSel = (d, key) => !!(d && d.open && key && (selections[d.date] || []).includes(key));
                                                    const sides = [];
                                                    if (!isSel(day, columns[i - 1])) sides.push('inset 2px 0 0 #b6e0c8');
                                                    if (!isSel(day, columns[i + 1])) sides.push('inset -2px 0 0 #b6e0c8');
                                                    if (!isSel(board.days[dayIndex - 1], col)) sides.push('inset 0 2px 0 #b6e0c8');
                                                    if (!isSel(board.days[dayIndex + 1], col)) sides.push('inset 0 -2px 0 #b6e0c8');
                                                    selStyle = { boxShadow: sides.join(', ') };
                                                }
                                            }
                                            return (
                                                <button
                                                    key={slot.key}
                                                    type="button"
                                                    className={`tmv-grid__cell is-free${selected ? ' is-selected' : ''}${unavailable ? ' is-unavailable' : ''}${canManage ? ' is-admin' : ''}`}
                                                    style={selStyle}
                                                    disabled={canManage ? false : (unavailable || (board.invited ? !canEdit : !canRequest))}
                                                    title={unavailable ? unavailableText : undefined}
                                                    onContextMenu={canManage ? (e) => openCtx(e, day.date, slot.key, false, null) : undefined}
                                                    onClick={(e) => {
                                                        if (unavailable && !canManage) return;
                                                        if (canManage && (!board.invited || !canEdit)) {
                                                            openCtx(e, day.date, slot.key, false, null);
                                                            return;
                                                        }
                                                        if (canManage && adminDoubleTap(e, day.date, slot.key, false, null)) {
                                                            if (board.invited && canEdit) toggle(day.date, slot.key);
                                                            return;
                                                        }
                                                        if (board.invited) {
                                                            if (canEdit) toggle(day.date, slot.key);
                                                        } else if (!canManage) {
                                                            setInviteOpen(true);
                                                        }
                                                    }}
                                                >
                                                    <i className="tmv-dot" /> {unavailable ? 'Occupato altrove' : label}
                                                </button>
                                            );
                                        });
                                        return isSplit ? <div key={col} className="tmv-grid__cellgroup">{cells}</div> : cells[0];
                                    })
                                )}
                            </div>
                        ))}
                    </div>
                </div>
                )}
            </div>

            {board.schedule && (
            <div className="tmv-print">
                <div className="tmv-print__head">
                    <h2>{board.structure.name}</h2>
                    <span>Turni · {monthLabel}</span>
                </div>
                <table className="tmv-print__table">
                    <thead>
                        <tr>
                            <th className="tmv-print__day">Giorno</th>
                            {columns.map((col) => <th key={col}>{rangeLabel(col)}</th>)}
                        </tr>
                    </thead>
                    <tbody>
                        {board.days.map((day) => (
                            <tr key={day.date} className={day.open ? '' : 'is-closed'}>
                                <th className="tmv-print__day">
                                    {day.day} <span>{day.weekday}</span>
                                    {day.open && day.note ? <em className="tmv-print__note">{day.note}</em> : null}
                                </th>
                                {day.open
                                    ? columns.map((col) => {
                                        const cellSlots = day.slots.filter((s) => columnFor[s.key] === col && s.taken);
                                        const parts = cellSlots.map((slot) => `${slot.key !== col ? `${shortRange(slot.key)} ` : ''}${slot.taken_by || '—'}`);
                                        return <td key={col}>{parts.join(' + ')}</td>;
                                    })
                                    : <td className="tmv-print__closed" colSpan={columns.length}>Chiuso{day.note ? ` — ${day.note}` : ''}</td>}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            )}

            {canEdit && (
                <div className="tmv-bar">
                    <span className={`tmv-bar__hours${mandatory && coveredHours < required ? ' is-warn' : ''}${mandatory && coveredHours > required ? ' is-info' : ''}`}><ClockIcon /> {barText}</span>
                    <button type="button" className="tmv-bar__btn" disabled={saving || !canSubmit} onClick={() => {
                        if (canSkip) return submit();
                        setSubmitError(null);
                        setSummaryOpen(true);
                    }}>
                        {canSkip ? 'Salta' : 'Conferma'}<span className="tmv-bar__btn-word">{canSkip ? ' turno' : ' turni'}</span>
                        <span className="tmv-bar__btn-chev" aria-hidden="true">›</span>
                    </button>
                </div>
            )}

            {canEdit && summaryOpen && (
                <ShiftSummaryModal
                    selections={selections}
                    selectedHours={selectedHours}
                    existingHours={Number(me?.done_hours) || 0}
                    requiredHours={mandatory ? required : 0}
                    structureName={board.structure.name}
                    monthLabel={monthLabel}
                    doctorName={actingAs?.name}
                    saving={saving}
                    error={submitError}
                    onClose={() => setSummaryOpen(false)}
                    onSubmit={submit}
                />
            )}

            {canManage && ctx && (
                <>
                    <div className="tmv-ctx__scrim" onClick={() => setCtx(null)} onContextMenu={(e) => { e.preventDefault(); setCtx(null); }} />
                    <div className="tmv-ctx" style={{ top: ctx.y, left: ctx.x }} role="menu">
                        <span className="tmv-ctx__head">{ctx.taken ? (ctx.name || 'Turno occupato') : 'Slot libero'} · {rangeLabel(ctx.slot)}</span>
                        <button type="button" className="tmv-ctx__item" onClick={() => openAssign(ctx)}>Assegna a…</button>
                        {ctx.taken && (
                            <button type="button" className="tmv-ctx__item is-danger" disabled={cellBusy} onClick={() => clearCell(ctx.date, ctx.slot)}>Elimina turno</button>
                        )}
                        {week && (() => {
                            const ctxDay = board.days.find((d) => d.date === ctx.date);
                            if (!ctxDay) return null;
                            return (
                                <button type="button" className="tmv-ctx__item" onClick={() => { setDayEdit({ iso: ctx.date, label: `${ctxDay.day} ${monthLabel}` }); setCtx(null); }}>Modifica giorno…</button>
                            );
                        })()}
                    </div>
                </>
            )}

            {canManage && dayMenu && (
                <>
                    <div className="tmv-ctx__scrim" onClick={() => setDayMenu(null)} onContextMenu={(e) => { e.preventDefault(); setDayMenu(null); }} />
                    <div className="tmv-ctx" style={{ top: dayMenu.y, left: dayMenu.x }} role="menu">
                        <span className="tmv-ctx__head">{dayMenu.label}</span>
                        <button type="button" className="tmv-ctx__item" onClick={() => { setDayEdit({ iso: dayMenu.iso, label: dayMenu.label }); setDayMenu(null); }}>Orario del giorno…</button>
                        <button type="button" className="tmv-ctx__item" disabled={calSaving} onClick={() => toggleDayClosed(dayMenu.iso, dayMenu.open)}>
                            {calSaving ? 'Salvataggio…' : (dayMenu.open ? 'Chiudi giorno' : 'Riapri giorno')}
                        </button>
                        {week && (week.overrides || {})[dayMenu.iso] && (
                            <button type="button" className="tmv-ctx__item is-danger" disabled={calSaving} onClick={() => {
                                const overrides = { ...(week.overrides || {}) };
                                delete overrides[dayMenu.iso];
                                saveWeek({ ...week, overrides });
                            }}>{calSaving ? 'Salvataggio…' : 'Ripristina default'}</button>
                        )}
                    </div>
                </>
            )}

            {canManage && calOpen && week && (
                <CalendarSettingsModal
                    week={week}
                    monthLabel={monthLabel}
                    saving={calSaving}
                    onSave={saveWeek}
                    onClose={() => setCalOpen(false)}
                />
            )}

            {canManage && dayEdit && week && (
                <DayOverrideModal
                    iso={dayEdit.iso}
                    dayLabel={dayEdit.label}
                    week={week}
                    saving={calSaving}
                    onSave={saveWeek}
                    onClose={() => setDayEdit(null)}
                />
            )}

            {canManage && newScheduleOpen && (
                <NewScheduleModal
                    key={defaultNewMonth}
                    defaultMonth={defaultNewMonth}
                    saving={newScheduleSaving}
                    onCreate={createNewSchedule}
                    onClose={() => setNewScheduleOpen(false)}
                />
            )}

            {canManage && assign && (
                <div className="tmp-overlay" onClick={closeAssign}>
                    <div className="tmp-modal" onClick={(e) => e.stopPropagation()}>
                        <div className="tmp-modal__head">
                            <h2>Assegna turno</h2>
                            <button type="button" className="tmp-modal__close" onClick={closeAssign} aria-label="Chiudi">×</button>
                        </div>
                        <div className="tmp-modal__body">
                            <p className="tmp-group__note">{assignDay ? `${assignDay.day} ${monthLabel}` : assign.date} · {rangeLabel(assign.slot)}</p>
                            {!docs ? (
                                <p className="tmd-empty">Caricamento…</p>
                            ) : (() => {
                                const q = normalizeText(docQuery.trim());
                                const doctorLabel = (d) => d.category || d.qualification || '';
                                const doctorSearch = (d) => normalizeText(`${d.name || ''} ${doctorLabel(d)} ${d.email || ''} ${d.phone || ''}`);
                                const match = (d) => !q || doctorSearch(d).includes(q);
                                const assigned = docs.assigned.filter(match);
                                const others = docs.others.filter(match);
                                return (
                                    <>
                                        <div className="tmv-assign__search">
                                            <input type="search" value={docQuery} onChange={(e) => setDocQuery(e.target.value)} placeholder="Cerca un medico…" aria-label="Cerca medico" autoFocus />
                                        </div>
                                        <div className="tmv-assign">
                                            {assigned.map((d) => (
                                                <button key={d.doctor_id} type="button" className="tmv-assign__row" disabled={cellBusy} onClick={() => pickDoctor(d, false)}>
                                                    <span className="tmv-assign__pos">{d.position}</span>
                                                    <span className="tmv-assign__name">{d.name}</span>
                                                    <span className="tmv-assign__qual">{doctorLabel(d)}</span>
                                                </button>
                                            ))}
                                            {others.length > 0 && <div className="tmv-assign__sep">Medici non associati all'orario</div>}
                                            {others.map((d) => (
                                                <button key={d.doctor_id} type="button" className="tmv-assign__row tmv-assign__row--other" disabled={cellBusy} onClick={() => pickDoctor(d, true)}>
                                                    <span className="tmv-assign__pos">+</span>
                                                    <span className="tmv-assign__name">{d.name}</span>
                                                    <span className="tmv-assign__qual">{doctorLabel(d)}</span>
                                                </button>
                                            ))}
                                            {assigned.length === 0 && others.length === 0 && <p className="tmd-empty">Nessun medico trovato.</p>}
                                        </div>
                                    </>
                                );
                            })()}
                        </div>
                    </div>
                </div>
            )}

            {canManage && rosterOpen && (
                <div className="tmp-overlay" onClick={closeRoster}>
                    <div className="tmp-modal tmp-modal--roster" onClick={(e) => e.stopPropagation()}>
                        <div className="tmp-modal__head">
                            <h2>Lista medici del turno</h2>
                            <button type="button" className="tmp-modal__close" onClick={closeRoster} aria-label="Chiudi">×</button>
                        </div>
                        <div className="tmp-modal__body">
                            <p className="tmp-group__note">{board.structure.name} · {monthLabel}</p>
                            {!rosterDraft ? (
                                <p className="tmd-empty">Caricamento…</p>
                            ) : (() => {
                                const q = normalizeText(rosterQuery.trim());
                                const doctorLabel = (d) => d.category || d.qualification || '';
                                const doctorSearch = (d) => normalizeText(`${d.name || ''} ${doctorLabel(d)} ${d.email || ''} ${d.phone || ''}`);
                                const match = (d) => !q || doctorSearch(d).includes(q);
                                const assigned = rosterDraft.assigned.filter(match);
                                const others = rosterDraft.others.filter(match);
                                return (
                                    <>
                                        <div className="tmv-roster-tabs" role="tablist" aria-label="Liste medici turno">
                                            <button type="button" className={rosterView === 'selected' ? 'is-active' : ''} onClick={() => setRosterView('selected')}>
                                                Selezionati <span>{rosterDraft.assigned.length}</span>
                                            </button>
                                            <button type="button" className={rosterView === 'full' ? 'is-active' : ''} onClick={() => setRosterView('full')}>
                                                Lista completa <span>{rosterDraft.others.length}</span>
                                            </button>
                                        </div>
                                        <div className="tmv-assign__search">
                                            <input type="search" value={rosterQuery} onChange={(e) => setRosterQuery(e.target.value)} placeholder="Cerca un medico…" aria-label="Cerca medico" autoFocus />
                                        </div>
                                        <div className="tmv-roster-columns">
                                            <section className={`tmv-roster-box tmv-roster-box--selected${rosterView === 'selected' ? ' is-mobile-active' : ''}`}>
                                                <div className="tmv-roster-box__head">
                                                    <h3>Selezionati</h3>
                                                    <span>{rosterDraft.assigned.length}</span>
                                                </div>
                                                <div className="tmv-roster-list">
                                                    {assigned.map((d) => (
                                                        <div key={d.doctor_id} className="tmv-roster-row tmv-roster-row--selected">
                                                            <span className="tmv-roster-row__position">{d.position}</span>
                                                            <span className="tmv-roster-row__name">{d.name}</span>
                                                            <span className="tmv-roster-row__qual">{doctorLabel(d)}</span>
                                                            {d.qualification_key === 'ruap_vincolato' && (
                                                                <label className="tmv-roster-row__hours">
                                                                    <input
                                                                        type="number"
                                                                        min="0"
                                                                        step="0.5"
                                                                        value={d.required_hours || 0}
                                                                        disabled={rosterBusy}
                                                                        aria-label={`Ore richieste a ${d.name}`}
                                                                        onChange={(event) => changeRosterRequiredHours(d.doctor_id, event.target.value)}
                                                                    />
                                                                    <span>h</span>
                                                                </label>
                                                            )}
                                                            <button type="button" className="tmv-roster-row__move tmv-roster-row__move--remove" disabled={rosterBusy} onClick={() => changeRosterDoctor(d.doctor_id, 'remove')} aria-label={`Rimuovi ${d.name}`}>−</button>
                                                        </div>
                                                    ))}
                                                    {assigned.length === 0 && <p className="tmd-empty">Nessun medico selezionato.</p>}
                                                </div>
                                            </section>
                                            <section className={`tmv-roster-box tmv-roster-box--available${rosterView === 'full' ? ' is-mobile-active' : ''}`}>
                                                <div className="tmv-roster-box__head">
                                                    <h3>Lista completa</h3>
                                                    <span>{rosterDraft.others.length}</span>
                                                </div>
                                                <div className="tmv-roster-list">
                                                    {others.map((d) => (
                                                        <button key={d.doctor_id} type="button" className="tmv-roster-row tmv-roster-row--available" disabled={rosterBusy} onClick={() => changeRosterDoctor(d.doctor_id, 'add')}>
                                                            <span className="tmv-roster-row__name">{d.name}</span>
                                                            <span className="tmv-roster-row__qual">{doctorLabel(d)}</span>
                                                            <span className="tmv-roster-row__move tmv-roster-row__move--add">+</span>
                                                        </button>
                                                    ))}
                                                    {others.length === 0 && <p className="tmd-empty">Nessun medico aggiungibile.</p>}
                                                </div>
                                            </section>
                                        </div>
                                    </>
                                );
                            })()}
                        </div>
                        <div className="tmp-modal__foot tmp-modal__foot--roster">
                            <div className="tmv-roster-actions">
                                <span className={`tmv-roster-actions__status${rosterConfirmed ? ' is-confirmed' : ''}`}>
                                    {rosterConfirmed ? 'Partecipanti confermati' : `${rosterAssignedCount} partecipanti`}
                                </span>
                                {rosterNotice && <span className="tmv-roster-actions__notice">{rosterNotice}</span>}
                                <div className="tmv-roster-actions__buttons">
                                    <button type="button" className="tmp-btn tmp-btn--primary" disabled={rosterBusy || !rosterAssignedCount || rosterConfirmed} onClick={confirmRoster}>
                                        {rosterBusy ? 'Conferma…' : (rosterConfirmed ? 'Confermato' : 'Conferma partecipanti')}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {monthPickerOpen && (
                <div className="tmp-overlay" onClick={() => setMonthPickerOpen(false)}>
                    <div className="tmp-modal tmp-modal--sm" onClick={(e) => e.stopPropagation()}>
                        <div className="tmp-modal__head">
                            <h2>Scegli il mese</h2>
                            <button type="button" className="tmp-modal__close" onClick={() => setMonthPickerOpen(false)} aria-label="Chiudi">×</button>
                        </div>
                        <div className="tmp-modal__body">
                            <ul className="tmv-monthlist">
                                {(board.months || []).map((item) => {
                                    const active = item.id === (board.schedule?.id || 0);
                                    return (
                                        <li key={item.id}>
                                            <button
                                                type="button"
                                                className={`tmv-monthlist__item${active ? ' is-active' : ''}`}
                                                onClick={() => {
                                                    setMonthPickerOpen(false);
                                                    if (!active) {
                                                        setSelections({});
                                                        setWeek(null);
                                                        setScheduleId(item.id);
                                                    }
                                                }}
                                            >
                                                <span className="tmv-monthlist__name">{monthText(item.month)}</span>
                                                <span className="tmv-monthlist__meta">
                                                    {canManage && item.is_default ? <em>predefinito</em> : null}
                                                    {item.closed ? <em className="is-closed">chiuso</em> : null}
                                                    {active && (
                                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12" /></svg>
                                                    )}
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    </div>
                </div>
            )}

            {inviteOpen && (
                <div className="tmp-overlay" onClick={() => setInviteOpen(false)}>
                    <div className="tmp-modal tmp-modal--sm" onClick={(e) => e.stopPropagation()}>
                        <div className="tmp-modal__head">
                            <h2>Richiedi invito</h2>
                            <button type="button" className="tmp-modal__close" onClick={() => setInviteOpen(false)} aria-label="Chiudi">×</button>
                        </div>
                        <div className="tmp-modal__body">
                            <p className="tmv-invite__text">Vuoi chiedere di essere invitato a coprire i turni di <strong>{monthLabel}</strong> presso <strong>{board.structure.name}</strong>?</p>
                            <p className="tmp-group__note">La richiesta sarà valutata da un amministratore: se approvata, entrerai in turnazione.</p>
                            {inviteError && <p className="tmp-error">{inviteError}</p>}
                        </div>
                        <div className="tmp-modal__foot">
                            <button type="button" className="tmp-btn tmp-btn--ghost" onClick={() => setInviteOpen(false)}>Annulla</button>
                            <button type="button" className="tmp-btn tmp-btn--primary" disabled={inviteSaving} onClick={sendInvite}>{inviteSaving ? 'Invio…' : 'Invia richiesta'}</button>
                        </div>
                    </div>
                </div>
            )}

            {impersonateOpen && (
                <div className="tmp-overlay" onClick={onImpersonateClose}>
                    <div className="tmp-modal" onClick={(e) => e.stopPropagation()}>
                        <div className="tmp-modal__head">
                            <h2>Compila al posto di…</h2>
                            <button type="button" className="tmp-modal__close" onClick={onImpersonateClose} aria-label="Chiudi">×</button>
                        </div>
                        <div className="tmp-modal__body">
                            <p className="tmp-group__note">Scegli un medico della coda di {monthLabel || 'questo mese'}: compilerai le sue disponibilità e l'invio resterà tracciato nel registro della coda.</p>
                            {!imperDocs ? (
                                <p className="tmd-empty">Caricamento…</p>
                            ) : (imperDocs.assigned || []).length === 0 ? (
                                <p className="tmd-empty">Nessun medico in coda per questo mese.</p>
                            ) : (
                                <div className="tmv-assign">
                                    {(imperDocs.assigned || []).map((d) => {
                                        const closed = ['done', 'skipped'].includes(d.status);
                                        return (
                                            <button
                                                key={d.doctor_id}
                                                type="button"
                                                className="tmv-assign__row"
                                                disabled={closed}
                                                onClick={() => {
                                                    setActingAs({ id: d.doctor_id, name: d.name });
                                                    setSelections({});
                                                    if (onImpersonateClose) onImpersonateClose();
                                                }}
                                            >
                                                <span className="tmv-assign__pos">{d.position}</span>
                                                <span className="tmv-assign__name">{d.name}</span>
                                                {d.is_current ? (
                                                    <span className="tmv-pill-status tmv-pill-status--done">Di turno adesso</span>
                                                ) : (
                                                    <span className={`tmv-pill-status ${closed ? 'tmv-pill-status--ro' : 'tmv-pill-status--wait'}`}>
                                                        {d.status === 'done' ? 'Completato' : (d.status === 'skipped' ? 'Saltato' : 'In coda')}
                                                    </span>
                                                )}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
