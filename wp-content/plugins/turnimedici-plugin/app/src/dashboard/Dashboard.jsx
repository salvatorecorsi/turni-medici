import { useState, useEffect } from '@wordpress/element';
import { getStructures } from './api';
import StructureCard from './structure-card';
import HoursPanel from './hours-panel';
import { qualLabel } from '../doctors/constants';

const PeopleIcon = () => (
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></svg>
);
const BuildingIcon = () => (
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="4" y="3" width="16" height="18" rx="2" /><line x1="9" y1="9" x2="9" y2="9.01" /><line x1="15" y1="9" x2="15" y2="9.01" /><line x1="9" y1="13" x2="9" y2="13.01" /><line x1="15" y1="13" x2="15" y2="13.01" /><line x1="10" y1="21" x2="14" y2="21" /></svg>
);
const ClockIcon = () => (
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><polyline points="12 7 12 12 15 14" /></svg>
);
const CalCheckIcon = () => (
    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" /><polyline points="9 16 11 18 15 14" /></svg>
);
const CheckIcon = () => (
    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><polyline points="8.5 12 11 14.5 16 9" /></svg>
);
const HourglassIcon = () => (
    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M6 2h12M6 22h12M6 2c0 4 3 6 6 7 3-1 6-3 6-7M6 22c0-4 3-6 6-7 3 1 6 3 6 7" /></svg>
);
const CalClockIcon = () => (
    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21 7.5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h6" /><line x1="3" y1="10" x2="21" y2="10" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="16" y1="2" x2="16" y2="6" /><circle cx="17.5" cy="16.5" r="4.5" /><polyline points="17.5 14.8 17.5 16.5 19 17.5" /></svg>
);
function Kpi({ icon, tone, label, value, sub }) {
    return (
        <div className="tmd-kpi">
            <span className={`tmd-kpi__icon tmd-kpi__icon--${tone}`}>{icon}</span>
            <div className="tmd-kpi__text">
                <span className="tmd-kpi__label">{label}</span>
                <span className="tmd-kpi__value">{value}</span>
                {sub && <span className="tmd-kpi__sub">{sub}</span>}
            </div>
        </div>
    );
}

const TURN_STATE = {
    completo:   { badge: 'Completato', cls: 'ok', icon: <CheckIcon />, title: (m) => m ? `${m} completato` : 'Turnazione completata', desc: null },
    tocca_a_te: { badge: 'Da compilare', cls: 'todo', icon: <CalCheckIcon />, title: (m) => m ? `Compila ${m}` : 'Compila i turni', desc: 'È il tuo turno di inserire le disponibilità.' },
    in_attesa:  { badge: 'In attesa', cls: 'wait', icon: <HourglassIcon />, title: (m) => m ? `In attesa · ${m}` : 'In attesa', desc: 'Compilano prima i medici che ti precedono.' },
    nessuna:    { badge: 'Nessuna', cls: 'ro', icon: <CalCheckIcon />, title: () => 'Nessuna turnazione attiva', desc: null },
};

function TurnHero({ turnazione, aft, nextShift, homeUrl }) {
    const t = turnazione && turnazione.status ? turnazione : { status: 'nessuna' };
    const s = TURN_STATE[t.status] || TURN_STATE.nessuna;
    const aftName = aft ? aft.name.replace(/^AFT\s+/i, '') : '';
    const where = [t.facility_name, aftName].filter(Boolean).join(' · ');
    const schedule = Number(t.schedule_id) || 0;
    const link = t.facility_slug ? `${homeUrl}${t.facility_slug}${schedule ? `?schedule=${schedule}` : ''}` : null;

    return (
        <section className={`tmd-hero tmd-hero--${s.cls}`}>
            <div className="tmd-hero__main">
                <span className="tmd-hero__icon">{s.icon}</span>
                <div className="tmd-hero__body">
                    <div className="tmd-hero__top">
                        <span className="tmd-hero__eyebrow">Turnazione corrente</span>
                        <span className={`tmd-badge tmd-badge--${s.cls}`}>{s.badge}</span>
                    </div>
                    <strong className="tmd-hero__title">{s.title(t.month_label)}</strong>
                    {where && <span className="tmd-hero__where">{where}</span>}
                    {s.desc && <p className="tmd-hero__desc">{s.desc}</p>}
                    {link && (
                        <div className="tmd-hero__actions">
                            <a className="tmd-btn tmd-btn--primary" href={link}>Vai ai turni <span aria-hidden="true">›</span></a>
                        </div>
                    )}
                </div>
            </div>
            {nextShift && (
                <aside className="tmd-hero__next">
                    <span className="tmd-hero__eyebrow">Prossimo turno</span>
                    <div className="tmd-hero__nextrow">
                        <span className="tmd-hero__nexticon"><CalClockIcon /></span>
                        <div className="tmd-hero__nexttext">
                            <strong>{nextShift.date} · {nextShift.slot}</strong>
                            {nextShift.facility && <span>{nextShift.facility}</span>}
                        </div>
                    </div>
                </aside>
            )}
        </section>
    );
}

export default function Dashboard({ me }) {
    const [structures, setStructures] = useState(null);

    useEffect(() => {
        getStructures().then(setStructures).catch(() => setStructures([]));
    }, []);

    const home = (window.tmDashboard || {}).homeUrl || '/';
    const hb = me.hours_bank;
    const now = new Date();
    const thisMonthHours = hb && now.getFullYear() === hb.year ? (hb.months[now.getMonth()] || 0) : 0;
    const managerScope = me.coordinated_facility
        ? `${me.coordinated_facility.name} · gestione limitata alla struttura`
        : (!me.is_admin && me.coordinated_aft ? `${me.coordinated_aft.name} · apri una sede per gestire turni e calendario` : 'Apri una sede per assegnare o liberare i turni');

    if (me.is_doctor === false) {
        return (
            <div className="tmd">
                <div className="tmd-pagehead">
                    <h1 className="tmd-pagetitle">Gestione turni</h1>
                    <p className="tmd-pagesub">{managerScope}</p>
                </div>
                <section className="tmd-panel">
                    <h2 className="tmd-panel__title">Sedi</h2>
                    {structures === null ? (
                        <p className="tmd-empty">Caricamento…</p>
                    ) : structures.length === 0 ? (
                        <p className="tmd-empty">Nessuna struttura disponibile.</p>
                    ) : (
                        <ul className="tmd-sedi">
                            {structures.map((s) => <StructureCard key={s.id} structure={s} />)}
                        </ul>
                    )}
                </section>
            </div>
        );
    }

    const doctorFullName = [me.first_name, me.last_name].filter(Boolean).join(' ');
    const doctorTitle = doctorFullName
        ? `${me.gender === 'female' ? 'Dott.ssa' : 'Dott.'} ${doctorFullName}`
        : 'Dashboard medico';

    return (
        <div className="tmd">
            <div className="tmd-pagehead">
                <h1 className="tmd-pagetitle">{doctorTitle}</h1>
                <p className="tmd-pagesub">Panoramica operativa e attività principali</p>
            </div>

            <section className="tmd-panel">
                <h2 className="tmd-panel__title">{me.is_admin ? 'Tutte le sedi' : 'Sedi AFT'}</h2>
                {structures === null ? (
                    <p className="tmd-empty">Caricamento…</p>
                ) : structures.length === 0 ? (
                    <p className="tmd-empty">Nessuna sede associata alla tua AFT.</p>
                ) : (
                    <ul className="tmd-sedi">
                        {structures.map((s) => <StructureCard key={s.id} structure={s} />)}
                    </ul>
                )}
            </section>

            <TurnHero turnazione={me.turnazione} aft={me.aft} nextShift={me.next_shift} homeUrl={home} />

            <div className="tmd-kpis">
                <Kpi icon={<PeopleIcon />} tone="blue" label="Assistiti" value={me.patients} sub={qualLabel(me.qualification)} />
                <Kpi icon={me.aft?.logo ? <img src={me.aft.logo} alt="" /> : <BuildingIcon />} tone="blue" label="AFT" value={me.aft ? me.aft.name.replace(/^AFT\s+/i, '') : '—'} sub="di riferimento" />
                <Kpi
                    icon={<ClockIcon />}
                    tone="violet"
                    label="Banca ore"
                    value={hb ? `${thisMonthHours}h` : '—'}
                    sub={hb ? `questo mese · ${hb.total}h annue${hb.limit > 0 ? ` / riferimento ${hb.limit}h` : ''}` : '—'}
                />
            </div>

            {hb && <HoursPanel bank={hb} currentMonthHours={thisMonthHours} />}
        </div>
    );
}
