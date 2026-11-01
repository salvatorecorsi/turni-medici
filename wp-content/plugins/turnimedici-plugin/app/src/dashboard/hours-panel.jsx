const MONTHS_SHORT = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
const MONTHS_LONG = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

const ClockIcon = () => (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><polyline points="12 7 12 12 15 14" /></svg>
);
const CalIcon = () => (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" /></svg>
);
const TargetIcon = () => (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><circle cx="12" cy="12" r="5" /><circle cx="12" cy="12" r="1" /></svg>
);
const CheckIcon = () => (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><polyline points="8.5 12 11 14.5 16 9" /></svg>
);
const AlertIcon = () => (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
);

export default function HoursPanel({ bank, currentMonthHours }) {
    const months = bank.months || [];
    const now = new Date();
    const curIdx = now.getFullYear() === bank.year ? now.getMonth() : 11;
    const thisMonth = currentMonthHours != null ? currentMonthHours : (months[curIdx] || 0);
    const hasReference = Number(bank.limit) > 0;
    const pct = hasReference ? Math.min(100, Math.round((bank.total / bank.limit) * 100)) : 0;
    const over = hasReference && bank.total > bank.limit;

    let lastData = 0;
    months.forEach((h, i) => { if (h > 0) lastData = i; });
    const upto = Math.max(curIdx, lastData);
    const visible = months.slice(0, upto + 1);
    const maxH = Math.max(1, ...visible);
    const peakIdx = visible.reduce((p, h, i, a) => (h > a[p] ? i : p), 0);

    return (
        <section className="tmd-panel tmd-hoursp">
            <h2 className="tmd-panel__title">Banca ore</h2>
            <div className="tmd-hoursp__grid">
                <div className="tmd-hoursp__stats">
                    <div className="tmd-hstat">
                        <span className="tmd-hstat__icon tmd-hstat__icon--violet"><ClockIcon /></span>
                        <div className="tmd-hstat__text"><strong>{thisMonth}h</strong><span>mese corrente</span></div>
                    </div>
                    <div className="tmd-hstat">
                        <span className="tmd-hstat__icon tmd-hstat__icon--blue"><CalIcon /></span>
                        <div className="tmd-hstat__text"><strong>{bank.total}h</strong><span>anno {bank.year}</span></div>
                    </div>
                    {hasReference && (
                        <div className="tmd-hstat">
                            <span className="tmd-hstat__icon tmd-hstat__icon--green"><TargetIcon /></span>
                            <div className="tmd-hstat__text"><strong>{bank.limit}h</strong><span>riferimento annuale</span></div>
                        </div>
                    )}
                    <p className={`tmd-hoursp__note${over ? ' is-warn' : ''}`}>
                        {over ? <AlertIcon /> : <CheckIcon />}
                        {over ? `Riferimento annuale di ${bank.limit}h superato` : 'Conteggio informativo dei turni assegnati'}
                    </p>
                </div>

                <div className="tmd-hoursp__chart">
                    <div className="tmd-hoursp__charthead">
                        <span className="tmd-hoursp__chartlabel">{hasReference ? 'Avanzamento annuale' : 'Distribuzione annuale'}</span>
                        {hasReference && <span className="tmd-hoursp__pct">{pct}%<small>{bank.total} / {bank.limit}h</small></span>}
                    </div>
                    {hasReference && <div className={`tmd-hoursp__bar${over ? ' is-over' : ''}`}><span style={{ width: pct + '%' }} /></div>}
                    <div className="tmd-hoursp__months">
                        {visible.map((h, i) => (
                            <div key={i} className="tmd-hbar" title={`${MONTHS_LONG[i]}: ${h}h`}>
                                <div className="tmd-hbar__track">
                                    <div className={`tmd-hbar__fill${i === peakIdx && h > 0 ? ' is-peak' : ''}`} style={{ height: Math.max(h > 0 ? 6 : 0, Math.round((h / maxH) * 100)) + '%' }} />
                                </div>
                                <span className="tmd-hbar__label">{MONTHS_SHORT[i]}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </section>
    );
}
