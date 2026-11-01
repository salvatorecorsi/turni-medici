import { CheckboxControl } from '@wordpress/components';
import { WEEKDAY_KEYS, WEEKDAY_SHORT } from './date-utils';
import SlotsEditor from './slots-editor';

export default function OptionsPanel({ viewMonth, defaults, onMonthChange, onDefaultsChange }) {
    const toggleWeekday = (key, closed) => {
        const set = new Set(defaults.closedWeekdays);
        if (closed) set.add(key); else set.delete(key);
        onDefaultsChange({ ...defaults, closedWeekdays: WEEKDAY_KEYS.filter((k) => set.has(k)) });
    };

    return (
        <aside className="tm-options">
            <div className="tm-options__group">
                <label className="tm-options__label">Mese</label>
                <input
                    type="month"
                    value={viewMonth}
                    onChange={(event) => onMonthChange(event.target.value)}
                />
            </div>

            <div className="tm-options__group">
                <label className="tm-options__label">Chiusure ordinarie</label>
                {WEEKDAY_KEYS.map((key) => (
                    <CheckboxControl
                        key={key}
                        label={WEEKDAY_SHORT[key]}
                        checked={defaults.closedWeekdays.includes(key)}
                        onChange={(closed) => toggleWeekday(key, closed)}
                        __nextHasNoMarginBottom
                    />
                ))}
            </div>

            <div className="tm-options__group">
                <label className="tm-options__label">Orario di default</label>
                <SlotsEditor
                    slots={defaults.slots}
                    onChange={(slots) => onDefaultsChange({ ...defaults, slots })}
                />
            </div>
        </aside>
    );
}
