import { useState } from '@wordpress/element';

const ArrowIcon = () => (
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><line x1="5" y1="12" x2="19" y2="12" /><polyline points="12 5 19 12 12 19" /></svg>
);

const badgeFor = (structure) => {
    if (structure.turn === 'completo') return { text: 'Completata', cls: 'ok' };
    if (structure.turn === 'tocca_a_te') return { text: 'Da compilare', cls: 'todo' };
    if (structure.turn === 'in_attesa') return { text: 'In attesa', cls: 'wait' };
    return { text: 'Attiva', cls: 'ro' };
};

export default function StructureCard({ structure }) {
    const [opening, setOpening] = useState(false);
    const home = (window.tmDashboard || {}).homeUrl || '/';
    const schedule = Number(structure.schedule_id) || 0;
    const boardUrl = `${home}${structure.slug}${schedule ? `?schedule=${schedule}` : ''}`;
    const badge = badgeFor(structure);
    const aft = structure.aft_name ? structure.aft_name.replace(/^AFT\s+/i, '') : '';
    const open = (e) => {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        setOpening(true);
    };

    return (
        <li className={`tmd-sede${opening ? ' is-loading' : ''}`}>
            <a className="tmd-sede__hit" href={boardUrl} onClick={open} aria-label={`Apri ${structure.name}`} />
            <span className="tmd-sede__icon" aria-hidden="true">{opening ? <span className="tmd-spinner" /> : <ArrowIcon />}</span>
            <div className="tmd-sede__body">
                <span className="tmd-sede__name">{structure.name}</span>
                <span className="tmd-sede__meta">Casa della Comunità{aft ? ` · ${aft}` : ''}</span>
                {structure.address && <span className="tmd-sede__addr">{structure.address}</span>}
            </div>
            <div className="tmd-sede__side">
                <span className={`tmd-badge tmd-badge--${opening ? 'wait' : badge.cls}`}>{opening ? 'Apertura...' : badge.text}</span>
            </div>
        </li>
    );
}
