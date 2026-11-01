import { useEffect, useRef } from '@wordpress/element';
import { Icon, closeSmall } from '@wordpress/icons';

const formatHours = (hours) => Number(hours).toLocaleString('it-IT', { maximumFractionDigits: 2 });
const formatDate = (date) => new Date(`${date}T12:00:00`).toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' });

export default function ShiftSummaryModal({ selections, selectedHours, existingHours, requiredHours, structureName, monthLabel, doctorName, saving, error, onClose, onSubmit }) {
    const modalRef = useRef(null);
    const selectedDays = Object.entries(selections).filter(([, slots]) => slots.length).sort(([a], [b]) => a.localeCompare(b));
    const count = selectedDays.reduce((total, [, slots]) => total + slots.length, 0);
    const coveredHours = existingHours + selectedHours;

    useEffect(() => {
        const previousFocus = document.activeElement;
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        modalRef.current?.focus();
        return () => {
            document.body.style.overflow = previousOverflow;
            if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true });
        };
    }, []);

    const close = () => { if (!saving) onClose(); };
    const handleKeyDown = (event) => {
        if (event.key === 'Escape') {
            event.stopPropagation();
            close();
        }
        if (event.key !== 'Tab') return;
        const buttons = [...modalRef.current.querySelectorAll('button:not(:disabled)')];
        const first = buttons[0];
        const last = buttons[buttons.length - 1];
        if (!first) {
            event.preventDefault();
        } else if (event.shiftKey && (document.activeElement === first || document.activeElement === modalRef.current)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (document.activeElement === last || document.activeElement === modalRef.current)) {
            event.preventDefault();
            first.focus();
        }
    };

    return (
        <div className="tmp-overlay" onClick={close}>
            <div className="tmp-modal tmp-modal--summary" role="dialog" aria-modal="true" aria-labelledby="tmv-summary-title" aria-describedby="tmv-summary-count tmv-summary-final" aria-busy={saving} tabIndex={-1} ref={modalRef} onKeyDown={handleKeyDown} onClick={(event) => event.stopPropagation()}>
                <div className="tmp-modal__head">
                    <h2 id="tmv-summary-title">Riepilogo turni</h2>
                    <button type="button" className="tmp-modal__close" disabled={saving} onClick={close} aria-label="Chiudi riepilogo" title="Chiudi riepilogo"><Icon icon={closeSmall} size={24} /></button>
                </div>
                <div className="tmp-modal__body">
                    <p className="tmp-group__note">{structureName} &middot; {monthLabel}</p>
                    {doctorName && <p className="tmv-summary__doctor">Per <strong>{doctorName}</strong></p>}
                    <p className="tmv-summary__count" id="tmv-summary-count">
                        <strong>{count} {count === 1 ? 'turno selezionato' : 'turni selezionati'}</strong>
                        <span>{formatHours(selectedHours)} {selectedHours === 1 ? 'ora' : 'ore'}</span>
                    </p>
                    <ul className="tmv-summary__list">
                        {selectedDays.map(([date, slots]) => (
                            <li key={date}>
                                <time dateTime={date}>{formatDate(date)}</time>
                                <div className="tmv-summary__slots">
                                    {[...slots].sort().map((slot) => <span key={slot}>{slot.replace('-', ' - ')}</span>)}
                                </div>
                            </li>
                        ))}
                    </ul>
                    {existingHours > 0 && <p className="tmv-summary__previous">Ore precedentemente assegnate: {formatHours(existingHours)}</p>}
                    {requiredHours > 0 && (
                        <p className={`tmv-summary__required${coveredHours < requiredHours ? ' is-warn' : ''}`}>
                            Totale coperto: <strong>{formatHours(coveredHours)} su {formatHours(requiredHours)} ore previste</strong>
                        </p>
                    )}
                    <p className="tmv-summary__final" id="tmv-summary-final">I turni inviati non sono modificabili.</p>
                    {error && <p className="tmp-error" role="alert">{error}</p>}
                </div>
                <div className="tmp-modal__foot">
                    <button type="button" className="tmp-btn tmp-btn--ghost" disabled={saving} onClick={close}>Aggiungi altri turni</button>
                    <button type="button" className="tmp-btn tmp-btn--primary" disabled={saving} onClick={onSubmit}>{saving ? 'Invio...' : 'Invia turni'}</button>
                </div>
            </div>
        </div>
    );
}
