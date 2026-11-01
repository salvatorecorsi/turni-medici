import { isoDate, weekdayKey, effectiveDay, WEEKDAY_SHORT, WEEKDAY_KEYS } from './date-utils';

const compactSlots = (slots) => slots.map((slot) => `${slot.from}–${slot.to}`).join('  ');

export default function CalendarGrid({ weeks, year, month, defaults, overrides, onEditDay, onContextMenu }) {
    return (
        <div className="tm-grid">
            <div className="tm-grid__head">
                {WEEKDAY_KEYS.map((key) => (
                    <div key={key} className="tm-grid__dow">{WEEKDAY_SHORT[key]}</div>
                ))}
            </div>

            {weeks.map((week, wi) => (
                <div key={wi} className="tm-grid__week">
                    {week.map((day, di) => {
                        if (!day) return <div key={di} className="tm-grid__cell is-empty" />;

                        const iso = isoDate(year, month, day);
                        const eff = effectiveDay(weekdayKey(year, month, day), overrides[iso], defaults);
                        const classes = ['tm-grid__cell'];
                        if (!eff.open) classes.push('is-closed');
                        if (eff.custom) classes.push('is-custom');

                        return (
                            <div
                                key={di}
                                className={classes.join(' ')}
                                onClick={() => onEditDay(iso)}
                                onContextMenu={(event) => {
                                    event.preventDefault();
                                    onContextMenu(iso, event.clientX, event.clientY);
                                }}
                            >
                                <span className="tm-grid__num">{day}</span>
                                {eff.note && <span className="tm-grid__note" title={eff.note}>●</span>}
                                {eff.custom && (
                                    <span className="tm-grid__hours">
                                        {eff.open ? compactSlots(eff.slots) : 'Chiuso'}
                                    </span>
                                )}
                            </div>
                        );
                    })}
                </div>
            ))}
        </div>
    );
}
