import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Button, Dropdown, ColorPalette } from '@wordpress/components';
import { Icon, dragHandle, chevronDown, chevronUp, closeSmall } from '@wordpress/icons';
import AmbulatoryScheduleEditor from './ambulatory-schedule-editor';

export default function AftDoctorRow({ doctor, color, palette, isOpen, ambulatori, onToggle, onColor, onRemove, onSchedule }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: doctor.id });
    const style = { transform: CSS.Transform.toString(transform), transition, opacity: isDragging ? 0.5 : 1 };

    return (
        <li ref={setNodeRef} style={style} className="tm-assign__row tm-ao-doc">
            <div className="tm-ao-doc__head">
                <span className="tm-ao-doc__handle" {...attributes} {...listeners}>
                    <Icon icon={dragHandle} />
                </span>
                <button type="button" className="tm-ao-doc__toggle" aria-expanded={isOpen} onClick={onToggle}>
                    <Icon icon={isOpen ? chevronUp : chevronDown} size={20} />
                    <span className="tm-assign__name">{doctor.full || doctor.name}</span>
                    <span className="tm-assign__meta">{(doctor.schedule || []).length} orari</span>
                </button>
                <Dropdown
                    popoverProps={{ placement: 'bottom-end' }}
                    renderToggle={({ isOpen: pickerOpen, onToggle: togglePicker }) => (
                        <button type="button" className="tm-ao-doc__dot" style={{ background: color }} aria-expanded={pickerOpen} aria-label="Colore nel prospetto" onClick={togglePicker} />
                    )}
                    renderContent={() => (
                        <div className="tm-ao-doc__picker">
                            <ColorPalette colors={palette} value={color} onChange={(value) => value && onColor(value)} clearable={false} />
                        </div>
                    )}
                />
                <Button icon={closeSmall} label="Togli dall'AFT" onClick={onRemove} />
            </div>
            {isOpen && <AmbulatoryScheduleEditor schedule={doctor.schedule || []} ambulatori={ambulatori} onChange={onSchedule} />}
        </li>
    );
}
