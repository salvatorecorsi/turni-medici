import { useState, useEffect } from '@wordpress/element';
import { SearchControl, Modal, Spinner, Notice, Button } from '@wordpress/components';
import { getOverview } from './api';

const BuildingIcon = () => (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="4" y="3" width="16" height="18" rx="2" /><line x1="9" y1="9" x2="9" y2="9.01" /><line x1="15" y1="9" x2="15" y2="9.01" /><line x1="9" y1="13" x2="9" y2="13.01" /><line x1="15" y1="13" x2="15" y2="13.01" /><line x1="10" y1="21" x2="14" y2="21" /></svg>
);
const PinIcon = () => (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0z" /><circle cx="12" cy="10" r="3" /></svg>
);
const AllIcon = () => (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><line x1="8" y1="6" x2="21" y2="6" /><line x1="8" y1="12" x2="21" y2="12" /><line x1="8" y1="18" x2="21" y2="18" /><line x1="3" y1="6" x2="3.01" y2="6" /><line x1="3" y1="12" x2="3.01" y2="12" /><line x1="3" y1="18" x2="3.01" y2="18" /></svg>
);

export default function SchedulesApp() {
    const [overview, setOverview] = useState(null);
    const [error, setError] = useState(null);
    const [open, setOpen] = useState(true);
    const [aftId, setAftId] = useState(null);
    const [q, setQ] = useState('');
    const config = window.tmSchedules || {};

    useEffect(() => { getOverview().then(setOverview).catch((e) => setError(e.message)); }, []);

    if (error) return <Notice status="error" isDismissible={false}>{error}</Notice>;
    if (!overview) return <Spinner />;

    const aft = aftId ? overview.find((a) => a.aft_id === aftId) : null;
    const term = q.trim().toLowerCase();
    const aftList = term ? overview.filter((a) => a.aft_name.toLowerCase().includes(term)) : overview;
    const structList = aft ? (term ? aft.structures.filter((s) => s.name.toLowerCase().includes(term)) : aft.structures) : [];

    const openModal = () => { setOpen(true); setAftId(null); setQ(''); };
    const pickAft = (id) => { setAftId(id); setQ(''); };
    const backToAft = () => { setAftId(null); setQ(''); };
    const goToStructure = (facilityId) => { window.location.href = `${config.listUrl}&tm_struttura=${facilityId}`; };
    return (
        <div className="tm-sched">
            <div className="tm-sched__topbar">
                <h1 className="wp-heading-inline">Turni</h1>
                <button type="button" className="components-button is-secondary" onClick={openModal}>Scegli struttura</button>
            </div>
            <p className="tm-sched__empty">Apri "Scegli struttura" per andare agli orari di una struttura.</p>

            {open && (
                <Modal title={aft ? aft.aft_name : "Scegli un'AFT"} onRequestClose={() => setOpen(false)} className="tm-sched-modal">
                    {!aft ? (
                        <>
                            <SearchControl value={q} onChange={setQ} placeholder="Cerca un'AFT…" __nextHasNoMarginBottom />
                            <div className="tm-sched__structs" style={{ marginTop: 14 }}>
                                <button type="button" className="tm-sched__struct tm-sched__struct--all" onClick={() => { window.location.href = config.listUrl; }}>
                                    <span className="tm-sched__struct-icon"><AllIcon /></span>
                                    <span className="tm-sched__struct-body"><strong>Mostra tutto</strong><span className="tm-sched__struct-count">tutti gli orari, senza filtro</span></span>
                                    <span className="tm-sched__struct-arr">›</span>
                                </button>
                                {aftList.length === 0 ? (
                                    <p className="tm-sched__empty">Nessuna AFT trovata.</p>
                                ) : aftList.map((a) => (
                                    <button key={a.aft_id} type="button" className="tm-sched__struct" onClick={() => pickAft(a.aft_id)}>
                                        <span className="tm-sched__struct-icon"><BuildingIcon /></span>
                                        <span className="tm-sched__struct-body">
                                            <strong>{a.aft_name}</strong>
                                            <span className="tm-sched__struct-count">{a.structures.length} struttur{a.structures.length === 1 ? 'a' : 'e'}</span>
                                        </span>
                                        <span className="tm-sched__struct-arr">›</span>
                                    </button>
                                ))}
                            </div>
                        </>
                    ) : (
                        <>
                            <Button variant="link" className="tm-sched__back" onClick={backToAft}>‹ Tutte le AFT</Button>
                            <SearchControl value={q} onChange={setQ} placeholder="Cerca una struttura…" __nextHasNoMarginBottom />
                            <div className="tm-sched__structs" style={{ marginTop: 14 }}>
                                {structList.length === 0 ? (
                                    <p className="tm-sched__empty">Nessuna struttura.</p>
                                ) : structList.map((s) => (
                                    <button key={s.id} type="button" className="tm-sched__struct" onClick={() => goToStructure(s.id)}>
                                        <span className="tm-sched__struct-icon"><PinIcon /></span>
                                        <span className="tm-sched__struct-body">
                                            <strong>{s.name}</strong>
                                            <span className="tm-sched__struct-count">{s.schedules.length} mes{s.schedules.length === 1 ? 'e' : 'i'}{s.default_schedule ? ' · default attivo' : ''}</span>
                                        </span>
                                        <span className="tm-sched__struct-arr">›</span>
                                    </button>
                                ))}
                            </div>
                        </>
                    )}
                </Modal>
            )}
        </div>
    );
}
