import { useState } from '@wordpress/element';
import { validateSlots } from '../date-utils';

const WEEKDAYS = [
    ['mon', 'Lunedì'],
    ['tue', 'Martedì'],
    ['wed', 'Mercoledì'],
    ['thu', 'Giovedì'],
    ['fri', 'Venerdì'],
    ['sat', 'Sabato'],
    ['sun', 'Domenica'],
];

function SlotsFields({ slots, onChange }) {
    const setSlot = (index, key, value) => {
        const next = slots.map((slot, i) => (i === index ? { ...slot, [key]: value } : slot));
        onChange(next);
    };
    const removeSlot = (index) => onChange(slots.filter((_, i) => i !== index));
    const addSlot = () => onChange([...slots, { from: '', to: '' }]);

    return (
        <div className="tmc-slots">
            {slots.map((slot, index) => (
                <div key={index} className="tmc-slots__row">
                    <input type="time" value={slot.from} onChange={(e) => setSlot(index, 'from', e.target.value)} aria-label="Dalle" />
                    <span className="tmc-slots__dash">–</span>
                    <input type="time" value={slot.to} onChange={(e) => setSlot(index, 'to', e.target.value)} aria-label="Alle" />
                    <button type="button" className="tmc-slots__remove" onClick={() => removeSlot(index)} aria-label="Rimuovi fascia">×</button>
                </div>
            ))}
            <button type="button" className="tmc-slots__add" onClick={addSlot}>+ Aggiungi fascia</button>
        </div>
    );
}

export function CalendarSettingsModal({ week, monthLabel, saving, onSave, onClose }) {
    const defaults = week.defaults || {};
    const [closedWeekdays, setClosedWeekdays] = useState(Array.isArray(defaults.closedWeekdays) ? defaults.closedWeekdays : []);
    const [slots, setSlots] = useState(Array.isArray(defaults.slots) ? defaults.slots : []);

    const toggleDay = (key) => {
        setClosedWeekdays((prev) => (prev.includes(key) ? prev.filter((d) => d !== key) : [...prev, key]));
    };
    const slotError = validateSlots(slots, 'Fasce standard');

    const submit = () => {
        if (slotError) return;
        onSave({ ...week, defaults: { closedWeekdays, slots } });
    };

    return (
        <div className="tmp-overlay" onClick={onClose}>
            <div className="tmp-modal" onClick={(e) => e.stopPropagation()}>
                <div className="tmp-modal__head">
                    <h2>Calendario · {monthLabel}</h2>
                    <button type="button" className="tmp-modal__close" onClick={onClose} aria-label="Chiudi">×</button>
                </div>
                <div className="tmp-modal__body">
                    <p className="tmp-group__note">Fasce orarie standard dei giorni aperti. Le eccezioni si impostano sul singolo giorno, tenendo premuto o col tasto destro.</p>
                    <SlotsFields slots={slots} onChange={setSlots} />
                    {slotError && <p className="tmp-error">{slotError}</p>}
                    <p className="tmc-label">Giorni di chiusura settimanale</p>
                    <div className="tmc-weekdays">
                        {WEEKDAYS.map(([key, label]) => (
                            <label key={key} className={`tmc-weekday${closedWeekdays.includes(key) ? ' is-closed' : ''}`}>
                                <input type="checkbox" checked={closedWeekdays.includes(key)} onChange={() => toggleDay(key)} />
                                {label.slice(0, 3)}
                            </label>
                        ))}
                    </div>
                </div>
                <div className="tmp-modal__foot">
                    <button type="button" className="tmp-btn tmp-btn--ghost" onClick={onClose}>Annulla</button>
                    <button type="button" className="tmp-btn tmp-btn--primary" disabled={saving || !!slotError} onClick={submit}>{saving ? 'Salvataggio…' : 'Salva calendario'}</button>
                </div>
            </div>
        </div>
    );
}

export function DayOverrideModal({ iso, dayLabel, week, saving, onSave, onClose }) {
    const defaults = week.defaults || {};
    const override = (week.overrides || {})[iso] || null;
    const [closed, setClosed] = useState(override ? !!override.closed : false);
    const [slots, setSlots] = useState(
        override && Array.isArray(override.slots) && override.slots.length
            ? override.slots
            : (Array.isArray(defaults.slots) ? defaults.slots : [])
    );
    const [note, setNote] = useState(override?.note || '');

    const apply = (nextOverride) => {
        const overrides = { ...(week.overrides || {}) };
        if (nextOverride) overrides[iso] = nextOverride;
        else delete overrides[iso];
        onSave({ ...week, overrides });
    };
    const slotError = closed ? '' : validateSlots(slots, 'Fasce del giorno');

    const submit = () => {
        if (slotError) return;
        const clean = { closed };
        if (!closed) clean.slots = slots;
        if (note.trim()) clean.note = note.trim();
        apply(clean);
    };

    return (
        <div className="tmp-overlay" onClick={onClose}>
            <div className="tmp-modal tmp-modal--sm" onClick={(e) => e.stopPropagation()}>
                <div className="tmp-modal__head">
                    <h2>{dayLabel}</h2>
                    <button type="button" className="tmp-modal__close" onClick={onClose} aria-label="Chiudi">×</button>
                </div>
                <div className="tmp-modal__body">
                    <label className="tmc-toggle">
                        <input type="checkbox" checked={closed} onChange={(e) => setClosed(e.target.checked)} />
                        Giorno chiuso
                    </label>
                    {!closed && <SlotsFields slots={slots} onChange={setSlots} />}
                    {slotError && <p className="tmp-error">{slotError}</p>}
                    <p className="tmc-label">Nota (opzionale)</p>
                    <input className="tmc-note" type="text" value={note} onChange={(e) => setNote(e.target.value)} placeholder="es. apertura ridotta" />
                </div>
                <div className="tmp-modal__foot">
                    {override && (
                        <button type="button" className="tmp-btn tmp-btn--ghost tmc-reset" disabled={saving} onClick={() => apply(null)}>Ripristina</button>
                    )}
                    <button type="button" className="tmp-btn tmp-btn--ghost" onClick={onClose}>Annulla</button>
                    <button type="button" className="tmp-btn tmp-btn--primary" disabled={saving || !!slotError} onClick={submit}>{saving ? 'Salvataggio…' : 'Salva'}</button>
                </div>
            </div>
        </div>
    );
}
