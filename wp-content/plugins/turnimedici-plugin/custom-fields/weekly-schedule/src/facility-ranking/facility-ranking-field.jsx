import { useState, useEffect } from '@wordpress/element';
import { Button, ComboboxControl, Spinner } from '@wordpress/components';
import { Icon, dragHandle } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';
import { DndContext, closestCenter } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy, arrayMove, useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';

const QUALIFICATION_LABELS = {
    mmg: 'MMG',
    ruap_volontario: 'RUAP Volontario',
    ruap_vincolato: 'RUAP Vincolato',
    ruap_corsista: 'Corsista RUAP',
    ca_titolare: 'CA Titolare',
    ca_temporaneo: 'CA Temporaneo',
};

function SortableDoctorRow({ id, position, name, detail, missing, onRemove }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id });
    const style = { transform: CSS.Transform.toString(transform), transition, opacity: isDragging ? 0.6 : 1 };
    return (
        <li ref={setNodeRef} style={style} className={`tm-facrank__row${isDragging ? ' is-dragging' : ''}${missing ? ' is-missing' : ''}`}>
            <span className="tm-facrank__handle" aria-label="Trascina per riordinare" {...attributes} {...listeners}>
                <Icon icon={dragHandle} />
            </span>
            <span className="tm-facrank__pos">{position}</span>
            <span className="tm-facrank__who">
                <strong>{name}</strong>
                {detail ? <small>{detail}</small> : null}
            </span>
            <Button className="tm-facrank__remove" size="small" icon="no-alt" label="Rimuovi dalla graduatoria" onClick={onRemove} />
        </li>
    );
}

export default function FacilityRankingField({ value, onChange }) {
    const ranking = (Array.isArray(value) ? value : []).map(Number).filter(Boolean);
    const [doctors, setDoctors] = useState(null);
    const [loadingBase, setLoadingBase] = useState(false);

    useEffect(() => {
        apiFetch({ path: '/turnimedici/v1/doctors' }).then(setDoctors).catch(() => setDoctors([]));
    }, []);

    const byId = {};
    (doctors || []).forEach((doctor) => { byId[doctor.id] = doctor; });

    const options = (doctors || [])
        .filter((doctor) => !ranking.includes(doctor.id))
        .map((doctor) => ({
            value: String(doctor.id),
            label: `${doctor.last_name} ${doctor.first_name}${QUALIFICATION_LABELS[doctor.qualification] ? ` · ${QUALIFICATION_LABELS[doctor.qualification]}` : ''}`.trim(),
        }));

    const handleDragEnd = ({ active, over }) => {
        if (!over || active.id === over.id) return;
        onChange(arrayMove(ranking, ranking.indexOf(active.id), ranking.indexOf(over.id)));
    };

    const fillFromGlobal = async () => {
        setLoadingBase(true);
        try {
            const data = await apiFetch({ path: '/turnimedici/v1/ranking/list' });
            onChange((data.list || []).map((row) => Number(row.id)).filter(Boolean));
        } catch {
            window.alert('Impossibile leggere la graduatoria calcolata.');
        } finally {
            setLoadingBase(false);
        }
    };

    return (
        <div className="tm-facrank">
            <p className="tm-facrank__help">
                Graduatoria imposta della struttura: chi è in lista viene chiamato per primo, nell'ordine qui sotto,
                ignorando le regole automatiche; chi non è in lista accoda con la graduatoria globale.
                Rimuovendo il blocco la graduatoria imposta si disattiva e la lista si cancella al salvataggio.
            </p>
            {doctors === null ? <Spinner /> : (
                <>
                    {ranking.length > 0 && (
                        <DndContext collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
                            <SortableContext items={ranking} strategy={verticalListSortingStrategy}>
                                <ol className="tm-facrank__list">
                                    {ranking.map((id, index) => {
                                        const doctor = byId[id];
                                        return (
                                            <SortableDoctorRow
                                                key={id}
                                                id={id}
                                                position={index + 1}
                                                name={doctor ? `${doctor.last_name} ${doctor.first_name}`.trim() : `#${id}`}
                                                detail={doctor ? (QUALIFICATION_LABELS[doctor.qualification] || doctor.qualification) : 'non in anagrafica'}
                                                missing={!doctor}
                                                onRemove={() => onChange(ranking.filter((other) => other !== id))}
                                            />
                                        );
                                    })}
                                </ol>
                            </SortableContext>
                        </DndContext>
                    )}
                    <div className="tm-facrank__add">
                        <ComboboxControl
                            label="Aggiungi medico"
                            placeholder="Cerca per cognome…"
                            value=""
                            options={options}
                            onChange={(selected) => { if (selected) onChange([...ranking, Number(selected)]); }}
                            __nextHasNoMarginBottom
                        />
                    </div>
                    <div className="tm-facrank__actions">
                        {ranking.length === 0 && (
                            <Button variant="secondary" isBusy={loadingBase} onClick={fillFromGlobal}>
                                Parti dalla graduatoria calcolata
                            </Button>
                        )}
                        {ranking.length > 0 && (
                            <Button variant="tertiary" isDestructive onClick={() => onChange([])}>
                                Svuota la lista
                            </Button>
                        )}
                    </div>
                </>
            )}
        </div>
    );
}
