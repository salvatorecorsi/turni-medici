import { useState, useEffect } from '@wordpress/element';
import { SearchControl, Modal, Spinner, Notice } from '@wordpress/components';
import { getOverview } from './api';

const BuildingIcon = () => (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="4" y="3" width="16" height="18" rx="2" /><line x1="9" y1="9" x2="9" y2="9.01" /><line x1="15" y1="9" x2="15" y2="9.01" /><line x1="9" y1="13" x2="9" y2="13.01" /><line x1="15" y1="13" x2="15" y2="13.01" /><line x1="10" y1="21" x2="14" y2="21" /></svg>
);
const AllIcon = () => (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><line x1="8" y1="6" x2="21" y2="6" /><line x1="8" y1="12" x2="21" y2="12" /><line x1="8" y1="18" x2="21" y2="18" /><line x1="3" y1="6" x2="3.01" y2="6" /><line x1="3" y1="12" x2="3.01" y2="12" /><line x1="3" y1="18" x2="3.01" y2="18" /></svg>
);

export default function StructuresApp() {
    const [overview, setOverview] = useState(null);
    const [error, setError] = useState(null);
    const [open, setOpen] = useState(true);
    const [q, setQ] = useState('');
    const config = window.tmStructures || {};

    useEffect(() => { getOverview().then(setOverview).catch((e) => setError(e.message)); }, []);

    if (error) return <Notice status="error" isDismissible={false}>{error}</Notice>;
    if (!overview) return <Spinner />;

    const term = q.trim().toLowerCase();
    const aftList = term ? overview.filter((a) => a.aft_name.toLowerCase().includes(term)) : overview;
    const goToAft = (id) => { window.location.href = `${config.listUrl}&tm_aft_filter=${id}`; };

    return (
        <div className="tm-sched">
            <div className="tm-sched__topbar">
                <h1 className="wp-heading-inline">Strutture</h1>
                <div style={{ display: 'flex', gap: 8 }}>
                    <button type="button" className="components-button is-secondary" onClick={() => { setOpen(true); setQ(''); }}>Filtra per AFT</button>
                    <a className="components-button is-primary" href={config.newUrl}>+ Nuova struttura</a>
                </div>
            </div>
            <p className="tm-sched__empty">Apri "Filtra per AFT" per vedere le strutture di un'AFT.</p>

            {open && (
                <Modal title="Scegli un'AFT" onRequestClose={() => setOpen(false)} className="tm-sched-modal">
                    <SearchControl value={q} onChange={setQ} placeholder="Cerca un'AFT…" __nextHasNoMarginBottom />
                    <div className="tm-sched__structs" style={{ marginTop: 14 }}>
                        <button type="button" className="tm-sched__struct tm-sched__struct--all" onClick={() => { window.location.href = config.listUrl; }}>
                            <span className="tm-sched__struct-icon"><AllIcon /></span>
                            <span className="tm-sched__struct-body"><strong>Mostra tutto</strong><span className="tm-sched__struct-count">tutte le strutture, senza filtro</span></span>
                            <span className="tm-sched__struct-arr">›</span>
                        </button>
                        {aftList.length === 0 ? (
                            <p className="tm-sched__empty">Nessuna AFT trovata.</p>
                        ) : aftList.map((a) => (
                            <button key={a.aft_id} type="button" className="tm-sched__struct" onClick={() => goToAft(a.aft_id)}>
                                <span className="tm-sched__struct-icon"><BuildingIcon /></span>
                                <span className="tm-sched__struct-body">
                                    <strong>{a.aft_name}</strong>
                                    <span className="tm-sched__struct-count">{a.structures.length} struttur{a.structures.length === 1 ? 'a' : 'e'}</span>
                                </span>
                                <span className="tm-sched__struct-arr">›</span>
                            </button>
                        ))}
                    </div>
                </Modal>
            )}
        </div>
    );
}
