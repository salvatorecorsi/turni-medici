import { useState } from '@wordpress/element';
import { Button, PanelBody } from '@wordpress/components';
import { InspectorControls } from '@wordpress/block-editor';
import {
    currentMonth,
    parseMonth,
    shiftMonth,
    monthMatrix,
    weekdayKey,
    effectiveDay,
    MONTH_LABELS,
} from './date-utils';
import OptionsPanel from './options-panel';
import CalendarGrid from './calendar-grid';
import DayModal from './day-modal';
import ContextMenu from './context-menu';

const DEFAULT_SLOTS = [
    { from: '08:00', to: '13:00' },
    { from: '15:00', to: '19:00' },
];

function normalize(value) {
    const v = value && typeof value === 'object' ? value : {};
    const defaults = v.defaults && typeof v.defaults === 'object' ? v.defaults : {};
    return {
        viewMonth: v.viewMonth || currentMonth(),
        defaults: {
            closedWeekdays: Array.isArray(defaults.closedWeekdays) ? defaults.closedWeekdays : ['sat', 'sun'],
            slots: Array.isArray(defaults.slots) ? defaults.slots : DEFAULT_SLOTS,
        },
        overrides: v.overrides && typeof v.overrides === 'object' ? v.overrides : {},
    };
}

export default function WeeklySchedule({ value, onChange }) {
    const data = normalize(value);
    const { viewMonth, defaults, overrides } = data;
    const { year, month } = parseMonth(viewMonth);
    const weeks = monthMatrix(year, month);

    const [menu, setMenu] = useState(null);
    const [editing, setEditing] = useState(null);

    const commit = (partial) => onChange({ ...data, ...partial });

    const setOverride = (iso, patch) =>
        commit({ overrides: { ...overrides, [iso]: { ...(overrides[iso] || {}), ...patch } } });

    const removeOverride = (iso) => {
        const next = { ...overrides };
        delete next[iso];
        commit({ overrides: next });
    };

    const menuItems = (iso) => {
        const eff = effectiveDay(weekdayKey(year, month, Number(iso.split('-')[2])), overrides[iso], defaults);
        const items = [
            { label: 'Orario personalizzato…', onClick: () => setEditing(iso) },
            { label: eff.open ? 'Chiudi giorno' : 'Riapri giorno', onClick: () => setOverride(iso, { closed: eff.open }) },
            { label: 'Nota…', onClick: () => setEditing(iso) },
        ];
        if (overrides[iso]) {
            items.push({ label: 'Ripristina default', destructive: true, onClick: () => removeOverride(iso) });
        }
        return items;
    };

    return (
        <>
            <InspectorControls>
                <PanelBody title="Configurazione orari" initialOpen>
                    <OptionsPanel
                        viewMonth={viewMonth}
                        defaults={defaults}
                        onMonthChange={(m) => commit({ viewMonth: m })}
                        onDefaultsChange={(next) => commit({ defaults: next })}
                    />
                </PanelBody>
            </InspectorControls>

            <div className="tm-cal">
                <header className="tm-cal__bar">
                    <Button variant="tertiary" onClick={() => commit({ viewMonth: shiftMonth(viewMonth, -1) })}>‹</Button>
                    <strong>{MONTH_LABELS[month - 1]} {year}</strong>
                    <Button variant="tertiary" onClick={() => commit({ viewMonth: shiftMonth(viewMonth, 1) })}>›</Button>
                </header>

                <CalendarGrid
                    weeks={weeks}
                    year={year}
                    month={month}
                    defaults={defaults}
                    overrides={overrides}
                    onEditDay={setEditing}
                    onContextMenu={(iso, x, y) => setMenu({ iso, x, y })}
                />
            </div>

            {menu && (
                <ContextMenu x={menu.x} y={menu.y} items={menuItems(menu.iso)} onClose={() => setMenu(null)} />
            )}

            {editing && (
                <DayModal
                    iso={editing}
                    defaults={defaults}
                    override={overrides[editing]}
                    onChange={(patch) => setOverride(editing, patch)}
                    onReset={() => { removeOverride(editing); setEditing(null); }}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
