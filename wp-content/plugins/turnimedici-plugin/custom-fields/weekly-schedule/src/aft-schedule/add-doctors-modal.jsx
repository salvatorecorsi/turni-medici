import { useState, useEffect } from '@wordpress/element';
import { Modal, Button, SearchControl, SelectControl, CheckboxControl, Spinner } from '@wordpress/components';
import { getAllDoctors } from './api';

const QUALIFICATION_LABELS = {
    mmg: 'MMG',
    ruap_volontario: 'RUAP volontario',
    ruap_vincolato: 'RUAP vincolato',
    ruap_corsista: 'Corsista RUAP',
    ca_titolare: 'CA Titolare',
    ca_temporaneo: 'CA Temporaneo',
};

const qualificationLabel = (qualification) => QUALIFICATION_LABELS[qualification] || qualification || 'Non configurato';

const TYPE_FILTERS = [
    { value: '', label: 'Tutte le tipologie' },
    { value: 'mmg', label: 'MMG' },
    { value: 'ruap', label: 'RUAP' },
    { value: 'ca', label: 'CA' },
];

const matchesType = (qualification, type) => {
    if (!type) return true;
    if (type === 'mmg') return qualification === 'mmg';
    return qualification.startsWith(`${type}_`);
};

export default function AddDoctorsModal({ assigned, onAdd, onClose }) {
    const [doctors, setDoctors] = useState(null);
    const [selected, setSelected] = useState([]);
    const [search, setSearch] = useState('');
    const [type, setType] = useState('');

    useEffect(() => { getAllDoctors().then(setDoctors); }, []);

    const toggle = (id) => setSelected(selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id]);

    const term = search.trim().toLowerCase();
    const doctorSearch = (doctor) => `${doctor.first_name || ''} ${doctor.last_name || ''} ${doctor.phone || ''} ${doctor.email || ''}`.toLowerCase();
    const available = (doctors || [])
        .filter((doctor) => !assigned.includes(doctor.id))
        .filter((doctor) => matchesType(doctor.qualification, type))
        .filter((doctor) => !term || doctorSearch(doctor).includes(term));

    return (
        <Modal title="Aggiungi medici" onRequestClose={onClose} className="tm-add-doctors">
            <div className="tm-add-doctors__controls">
                <SearchControl value={search} onChange={setSearch} placeholder="Cerca per nome, telefono o email" __next40pxDefaultSize __nextHasNoMarginBottom />
                <SelectControl value={type} options={TYPE_FILTERS} onChange={setType} __next40pxDefaultSize __nextHasNoMarginBottom />
            </div>

            <div className="tm-add-doctors__table-wrap">
                {doctors === null ? (
                    <Spinner />
                ) : (
                    <table className="tm-add-doctors__table">
                        <tbody>
                            {available.map((doctor) => (
                                <tr key={doctor.id} onClick={() => toggle(doctor.id)}>
                                    <td className="tm-add-doctors__check" onClick={(e) => e.stopPropagation()}>
                                        <CheckboxControl checked={selected.includes(doctor.id)} onChange={() => toggle(doctor.id)} __nextHasNoMarginBottom />
                                    </td>
                                    <td>{doctor.first_name} {doctor.last_name}</td>
                                    <td className="tm-add-doctors__type">{qualificationLabel(doctor.qualification)}</td>
                                </tr>
                            ))}
                            {available.length === 0 && (
                                <tr><td colSpan={3} className="tm-add-doctors__empty">Nessun medico disponibile.</td></tr>
                            )}
                        </tbody>
                    </table>
                )}
            </div>

            <div className="tm-add-doctors__footer">
                <Button variant="tertiary" onClick={onClose}>Annulla</Button>
                <Button variant="primary" onClick={() => onAdd(selected)} disabled={!selected.length}>
                    Aggiungi{selected.length ? ` (${selected.length})` : ''}
                </Button>
            </div>
        </Modal>
    );
}
