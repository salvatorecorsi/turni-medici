const DAYS = [
    { id: 'lun', label: 'Lunedì' },
    { id: 'mar', label: 'Martedì' },
    { id: 'mer', label: 'Mercoledì' },
    { id: 'gio', label: 'Giovedì' },
    { id: 'ven', label: 'Venerdì' },
];

export default function AmbulatoryScheduleEditor({ schedule, ambulatori, onChange, emptyHint }) {
    const rows = Array.isArray(schedule) ? schedule : [];
    const ambs = Array.isArray(ambulatori) ? ambulatori : [];

    const add = () => onChange([...rows, { amb: ambs[0] ? ambs[0].id : 0, day: 'lun', start: '09:00', end: '12:00', booking: 'appuntamento' }]);
    const upd = (i, patch) => onChange(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));
    const del = (i) => onChange(rows.filter((_, j) => j !== i));

    if (ambs.length === 0) {
        return <p className="tm-ao-hint">{emptyHint || 'Nessun ambulatorio in questa AFT. Crea una struttura con modalità "Ambulatorio".'}</p>;
    }

    return (
        <div className="tm-ao-sched">
            {rows.length > 0 && (
                <div className="tm-ao-rows">
                    <div className="tm-ao-row tm-ao-row--head"><span>Giorno</span><span>Ambulatorio</span><span>Orario</span><span>Accesso</span><span></span></div>
                    {rows.map((r, i) => (
                        <div className="tm-ao-row" key={i}>
                            <select value={r.day} onChange={(e) => upd(i, { day: e.target.value })}>{DAYS.map((d) => <option key={d.id} value={d.id}>{d.label}</option>)}</select>
                            <select value={r.amb} onChange={(e) => upd(i, { amb: Number(e.target.value) })}>{ambs.map((a) => <option key={a.id} value={a.id}>{a.label}{a.address ? ` - ${a.address}` : ''}</option>)}</select>
                            <div className="tm-ao-row__time">
                                <input type="time" step={300} value={r.start} onChange={(e) => upd(i, { start: e.target.value })} />
                                <span className="tm-ao-row__dash">–</span>
                                <input type="time" step={300} value={r.end} onChange={(e) => upd(i, { end: e.target.value })} />
                            </div>
                            <select value={r.booking === 'libero' ? 'libero' : 'appuntamento'} onChange={(e) => upd(i, { booking: e.target.value })}><option value="appuntamento">Su appuntamento</option><option value="libero">Accesso libero</option></select>
                            <button type="button" className="tm-ao-x" aria-label="Rimuovi orario" onClick={() => del(i)}>×</button>
                        </div>
                    ))}
                </div>
            )}
            <button type="button" className="tm-ao-add" onClick={add}>+ Orario</button>
        </div>
    );
}
