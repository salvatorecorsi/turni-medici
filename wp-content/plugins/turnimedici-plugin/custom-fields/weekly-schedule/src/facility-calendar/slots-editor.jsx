import { Button } from '@wordpress/components';
import { validateSlots } from './date-utils';

export default function SlotsEditor({ slots, onChange }) {
    const slotError = validateSlots(slots);

    const setSlot = (index, field, value) =>
        onChange(slots.map((slot, i) => (i === index ? { ...slot, [field]: value } : slot)));

    const addSlot = () => onChange([...slots, { from: '', to: '' }]);
    const removeSlot = (index) => onChange(slots.filter((_, i) => i !== index));

    return (
        <div className="tm-slots">
            {slots.map((slot, index) => (
                <div key={index} className="tm-slots__row">
                    <input
                        type="time"
                        value={slot.from}
                        onChange={(event) => setSlot(index, 'from', event.target.value)}
                    />
                    <span className="tm-slots__dash">–</span>
                    <input
                        type="time"
                        value={slot.to}
                        onChange={(event) => setSlot(index, 'to', event.target.value)}
                    />
                    <Button isDestructive variant="tertiary" onClick={() => removeSlot(index)}>
                        ×
                    </Button>
                </div>
            ))}
            {slotError && <p className="tm-slots__error">{slotError}</p>}
            <Button variant="secondary" onClick={addSlot}>+ Fascia</Button>
        </div>
    );
}
