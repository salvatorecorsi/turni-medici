import { Modal, ToggleControl, TextareaControl, Button } from '@wordpress/components';
import { weekdayKey, WEEKDAY_SHORT, MONTH_LABELS } from './date-utils';
import SlotsEditor from './slots-editor';

export default function DayModal({ iso, defaults, override, onChange, onReset, onClose }) {
    const [year, month, day] = iso.split('-').map(Number);
    const weekKey = weekdayKey(year, month, day);

    const closed = override?.closed ?? defaults.closedWeekdays.includes(weekKey);
    const slots = override?.slots ?? defaults.slots;
    const note = override?.note ?? '';

    return (
        <Modal
            title={`${WEEKDAY_SHORT[weekKey]} ${day} ${MONTH_LABELS[month - 1]}`}
            onRequestClose={onClose}
            className="tm-day-modal"
        >
            <ToggleControl
                label="Chiuso"
                checked={closed}
                onChange={(value) => onChange({ closed: value })}
                __nextHasNoMarginBottom
            />

            {!closed && (
                <div className="tm-day-modal__slots">
                    <SlotsEditor slots={slots} onChange={(next) => onChange({ closed: false, slots: next })} />
                </div>
            )}

            <TextareaControl
                label="Nota"
                value={note}
                onChange={(value) => onChange({ note: value })}
                __nextHasNoMarginBottom
            />

            <div className="tm-day-modal__footer">
                {override && (
                    <Button variant="tertiary" isDestructive onClick={onReset}>
                        Ripristina default
                    </Button>
                )}
                <Button variant="primary" onClick={onClose}>Fatto</Button>
            </div>
        </Modal>
    );
}
