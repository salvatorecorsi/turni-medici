import { useState, useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';
import CaRosterManager from './ca-roster-manager';

const config = window.tmDoctors || {};

async function api(path, options = {}) {
    const response = await fetch(config.restUrl + path, {
        ...options,
        headers: { 'X-WP-Nonce': config.nonce, ...(options.headers || {}) },
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data?.message || 'Errore di rete');
    return data;
}

export default function CaRosterBar({ onChanged }) {
    const [rosters, setRosters] = useState(null);
    const [managerOpen, setManagerOpen] = useState(false);

    const load = () => api('doctors/ca-rosters').then(setRosters).catch(() => setRosters([]));

    useEffect(() => { load(); }, []);

    if (!rosters) return null;

    const active = rosters.find((roster) => roster.active) || null;

    return (
        <div className="tm-rosterbar">
            <span className="tm-rosterbar__label">Elenco CA in vigore</span>
            {active ? (
                <span className="tm-rosterbar__current">
                    <span className="tm-rosterbar__badge">ATTIVO</span>
                    <span className="tm-rosterbar__filename">{active.filename}</span>
                    <span className="tm-rosterbar__detail">
                        {active.horizon ? `${active.horizon.start} → ${active.horizon.end} · ` : ''}{active.doctors} medici
                    </span>
                </span>
            ) : (
                <span className="tm-rosterbar__empty">
                    {rosters.length
                        ? 'Nessun elenco in vigore: aprilo dall’archivio e attivalo col tasto destro.'
                        : 'Nessun elenco caricato: apri l’archivio e trascina il primo file CSV.'}
                </span>
            )}
            <Button variant="secondary" onClick={() => setManagerOpen(true)}>Archivio elenchi</Button>

            {managerOpen && (
                <CaRosterManager
                    rosters={rosters}
                    onClose={() => setManagerOpen(false)}
                    onReload={load}
                    onDoctorsChanged={onChanged}
                />
            )}
        </div>
    );
}
