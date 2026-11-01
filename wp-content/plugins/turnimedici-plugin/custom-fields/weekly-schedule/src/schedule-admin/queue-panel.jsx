import { useState } from '@wordpress/element';
import { Button, Notice, SelectControl, TextControl } from '@wordpress/components';
import { DndContext, closestCenter } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy, arrayMove, useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Icon, dragHandle } from '@wordpress/icons';

const STATUS_OPTIONS = [
    { value: 'pending', label: 'In coda' },
    { value: 'done', label: 'Completato' },
    { value: 'skipped', label: 'Saltato' },
];

const STATUS_LABELS = { pending: 'In coda', done: 'Completato', skipped: 'Saltato' };

const doctorMismatches = (assignment, scheduleAftId) => {
    const live = assignment.live || {};
    const found = [];
    if (live.qualification && live.qualification !== assignment.qualification) {
        found.push(`Ruolo nel profilo: ${live.qualification_label || live.qualification}`);
    }
    const mandatoryMonthly = Number(live.mandatory_monthly_hours) || 0;
    if (mandatoryMonthly > 0 && mandatoryMonthly !== Number(assignment.required_hours)) {
        found.push(`Ore nel profilo: ${live.weekly_hours}h/settimana (${mandatoryMonthly}h nel mese)`);
    }
    if (scheduleAftId && live.home_aft_id !== scheduleAftId) {
        found.push(live.home_aft_name ? `AFT del profilo: ${live.home_aft_name}` : 'AFT del profilo non corrispondente');
    }
    return found;
};

function SortableQueueRow({ id, open, dragDisabled, header, children }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id, disabled: dragDisabled });
    const style = { transform: CSS.Transform.toString(transform), transition, opacity: isDragging ? 0.6 : 1 };
    return (
        <li ref={setNodeRef} style={style} className={`tm-schedule-admin__queue-row${open ? ' is-open' : ''}${isDragging ? ' is-dragging' : ''}`}>
            <div className="tm-schedule-admin__queue-head">
                <span className={`tm-schedule-admin__queue-handle${dragDisabled ? ' is-disabled' : ''}`} aria-label="Trascina per riordinare" {...attributes} {...listeners}>
                    <Icon icon={dragHandle} />
                </span>
                {header}
            </div>
            {children}
        </li>
    );
}

export default function QueuePanel({ assignments, qualifications, aft, currentDoctorId, busy, locked, lockedMessage, onApply, onReorder }) {
    const [openDoctorId, setOpenDoctorId] = useState(0);
    const [draft, setDraft] = useState(null);
    const [pendingOrder, setPendingOrder] = useState(null);

    const displayed = pendingOrder
        ? pendingOrder.map((doctorId) => assignments.find((assignment) => assignment.doctor_id === doctorId)).filter(Boolean)
        : assignments;

    const toggleRow = (assignment) => {
        if (openDoctorId === assignment.doctor_id) {
            setOpenDoctorId(0);
            setDraft(null);
            return;
        }
        setOpenDoctorId(assignment.doctor_id);
        setDraft({
            required_hours: String(assignment.required_hours),
            status: assignment.status || 'pending',
            qualification: assignment.qualification,
        });
    };

    const isChanged = (assignment) => Boolean(draft) && (
        Number(draft.required_hours) !== Number(assignment.required_hours)
        || draft.status !== (assignment.status || 'pending')
        || draft.qualification !== assignment.qualification
    );

    const alignToProfile = (assignment) => {
        const live = assignment.live || {};
        const mandatoryMonthly = Number(live.mandatory_monthly_hours) || 0;
        setDraft((current) => ({
            ...current,
            qualification: live.qualification || current.qualification,
            required_hours: mandatoryMonthly > 0 ? String(mandatoryMonthly) : current.required_hours,
        }));
    };

    const applyRow = (assignment) => onApply({
        doctor_id: assignment.doctor_id,
        required_hours: Number(draft.required_hours) || 0,
        status: draft.status,
        qualification: draft.qualification,
    }, assignment.doctor_name);

    const handleDragStart = () => {
        setOpenDoctorId(0);
        setDraft(null);
    };

    const handleDragEnd = ({ active, over }) => {
        if (!over || active.id === over.id) return;
        const ids = displayed.map((assignment) => assignment.doctor_id);
        const nextOrder = arrayMove(ids, ids.indexOf(active.id), ids.indexOf(over.id));
        setPendingOrder(nextOrder);
        onReorder(nextOrder).finally(() => setPendingOrder(null));
    };

    const dragDisabled = locked || Boolean(busy) || assignments.length < 2;

    return (
        <div className="tm-schedule-admin__json-panel">
            <div className="tm-schedule-admin__section-bar">
                <p className="tm-schedule-admin__section-desc">
                    Ogni modifica applicata da qui scrive direttamente nel database: crea uno snapshot di sicurezza, riallinea le firme e non invia email. La bozza JSON non viene toccata. Trascina le righe per riordinare la coda: gli stati restano invariati.
                </p>
            </div>
            {locked && <Notice status="warning" isDismissible={false}>{lockedMessage}</Notice>}
            {assignments.length === 0 && <p className="tm-schedule-admin__empty">Nessun medico in coda.</p>}
            <DndContext collisionDetection={closestCenter} onDragStart={handleDragStart} onDragEnd={handleDragEnd}>
                <SortableContext items={displayed.map((assignment) => assignment.doctor_id)} strategy={verticalListSortingStrategy}>
                    <ul className="tm-schedule-admin__queue">
                        {displayed.map((assignment, index) => {
                            const open = openDoctorId === assignment.doctor_id;
                            const issues = doctorMismatches(assignment, aft?.id);
                            const qualificationOptions = draft && !qualifications.some((entry) => entry.value === draft.qualification)
                                ? [{ value: draft.qualification, label: draft.qualification || '—' }, ...qualifications]
                                : qualifications;
                            return (
                                <SortableQueueRow
                                    key={assignment.id}
                                    id={assignment.doctor_id}
                                    open={open}
                                    dragDisabled={dragDisabled}
                                    header={
                                        <button type="button" className="tm-schedule-admin__queue-toggle" aria-expanded={open} onClick={() => toggleRow(assignment)}>
                                            <span className="tm-schedule-admin__queue-pos">{pendingOrder ? index + 1 : assignment.position}</span>
                                            <span className="tm-schedule-admin__queue-name">
                                                {assignment.doctor_name}
                                                {assignment.doctor_id === currentDoctorId && <em className="tm-schedule-admin__queue-current">di turno</em>}
                                            </span>
                                            <span className="tm-schedule-admin__queue-meta">
                                                {Number(assignment.required_hours) > 0 && <span className="tm-schedule-admin__queue-hours">{assignment.required_hours}h</span>}
                                                <span className={`tm-schedule-admin__queue-status tm-schedule-admin__queue-status--${assignment.status || 'pending'}`}>
                                                    {STATUS_LABELS[assignment.status] || 'In coda'}
                                                </span>
                                                {issues.length > 0 && <span className="tm-schedule-admin__queue-warn">{issues.length === 1 ? '1 dato da verificare' : `${issues.length} dati da verificare`}</span>}
                                            </span>
                                            <span aria-hidden="true" className="tm-schedule-admin__queue-chevron">{open ? '▲' : '▼'}</span>
                                        </button>
                                    }
                                >
                                    {open && draft && (
                                        <div className="tm-schedule-admin__queue-body">
                                            <div className="tm-schedule-admin__queue-fields">
                                                <TextControl label="Ore richieste nel mese" type="number" min={0} step={0.5} value={draft.required_hours} onChange={(value) => setDraft({ ...draft, required_hours: value })} __next40pxDefaultSize __nextHasNoMarginBottom />
                                                <SelectControl label="Stato coda" value={draft.status} options={STATUS_OPTIONS} onChange={(value) => setDraft({ ...draft, status: value })} __next40pxDefaultSize __nextHasNoMarginBottom />
                                                <SelectControl label="Qualifica nel turno" value={draft.qualification} options={qualificationOptions} onChange={(value) => setDraft({ ...draft, qualification: value })} __next40pxDefaultSize __nextHasNoMarginBottom />
                                            </div>
                                            <div className="tm-schedule-admin__queue-info">
                                                <span>Turni inseriti: {assignment.shift_count}</span>
                                                {assignment.turn_started_at && <span>Turno iniziato: {assignment.turn_started_at.replace('T', ' ')}</span>}
                                            </div>
                                            {issues.length > 0 && (
                                                <div className="tm-schedule-admin__queue-issues">
                                                    <strong>Profilo medico non allineato</strong>
                                                    <ul>{issues.map((issue) => <li key={issue}>{issue}</li>)}</ul>
                                                    <Button variant="tertiary" disabled={busy} onClick={() => alignToProfile(assignment)}>Allinea ai dati del profilo</Button>
                                                </div>
                                            )}
                                            <div className="tm-schedule-admin__queue-actions">
                                                <Button variant="tertiary" disabled={busy} onClick={() => toggleRow(assignment)}>Chiudi</Button>
                                                <Button variant="primary" disabled={busy || locked || !isChanged(assignment)} onClick={() => applyRow(assignment)}>Applica al DB</Button>
                                            </div>
                                        </div>
                                    )}
                                </SortableQueueRow>
                            );
                        })}
                    </ul>
                </SortableContext>
            </DndContext>
        </div>
    );
}
