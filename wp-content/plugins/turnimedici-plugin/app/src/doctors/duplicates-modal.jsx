import { useState, useEffect } from '@wordpress/element';
import { Modal, Button } from '@wordpress/components';
import { getDoctorActivity, resolveDuplicateDoctors } from './api';
import { qualLabel } from './constants';

const normalizeText = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

export function findDuplicateGroups(doctors) {
    const groups = {};
    (doctors || []).forEach((doctor) => {
        const key = normalizeText(`${doctor.first_name || ''} ${doctor.last_name || ''}`).replace(/\s+/g, ' ').trim();
        if (!key) return;
        (groups[key] = groups[key] || []).push(doctor);
    });
    return Object.values(groups).filter((group) => group.length > 1);
}

const formatDate = (iso) => {
    const [year, month, day] = String(iso || '').split('-');
    return year && month && day ? `${day}/${month}/${year}` : '';
};

const activityText = (row) => {
    if (!row) return 'Resoconto attività non disponibile.';
    if (!row.has_activity) return 'Nessuna attività registrata.';
    const parts = [
        `${row.assignments} turnazion${row.assignments === 1 ? 'e' : 'i'}`,
        `${row.shifts} turn${row.shifts === 1 ? 'o' : 'i'} assegnat${row.shifts === 1 ? 'o' : 'i'}`,
    ];
    if (row.last_shift) parts.push(`ultimo turno ${formatDate(row.last_shift)}`);
    if (row.facilities?.length) parts.push(`strutture: ${row.facilities.join(', ')}`);
    return parts.join(' · ');
};

export function DuplicateDoctorsModal({ groups, afts, onClose, onResolved }) {
    const [activity, setActivity] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        const ids = groups.flat().map((doctor) => doctor.id);
        Promise.all(ids.map((id) => getDoctorActivity(id).catch(() => null))).then((rows) => {
            const byId = {};
            rows.forEach((row) => {
                if (row) byId[row.doctor_id] = row;
            });
            setActivity(byId);
        });
    }, []);

    const aftName = (id) => (afts.find((aft) => aft.id === id) || {}).name || '—';
    const groupActivityLoaded = (group) => group.every((item) => activity && activity[item.id]);

    const keepDoctor = async (group, doctor) => {
        const removes = group.filter((item) => item.id !== doctor.id);
        const removesWithActivity = removes.filter((item) => activity?.[item.id]?.has_activity);
        const removesInSchedules = removes.filter((item) => (activity?.[item.id]?.assignments || 0) > 0);
        const removeLines = removes.map((item) => {
            const flag = activity?.[item.id]?.has_activity ? ' — ATTENZIONE: ha attività registrate' : '';
            return `• ${item.first_name} ${item.last_name} (${item.email})${flag}`;
        });
        const warnings = [];
        if (removesWithActivity.length) warnings.push('I turni e le assegnazioni dei medici eliminati verranno rimossi dal sistema.');
        if (removesInSchedules.length) warnings.push('Se erano in una lista partecipanti già confermata, quei mesi risulteranno da riconfermare e la compilazione resterà sospesa fino alla nuova conferma.');
        const warning = warnings.length ? warnings.join('\n') + '\n\n' : '';
        const message = `Tenere ${doctor.first_name} ${doctor.last_name} (${doctor.email}) ed eliminare definitivamente:\n\n${removeLines.join('\n')}\n\n${warning}L'operazione non è reversibile. Continuare?`;
        if (!window.confirm(message)) return;
        setBusy(true);
        try {
            const result = await resolveDuplicateDoctors(doctor.id, removes.map((item) => item.id));
            onResolved(result.removed || []);
        } catch (err) {
            window.alert(err.message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal title="Medici con lo stesso nome" onRequestClose={onClose} className="tm-duplicates-modal">
            <p className="tm-duplicates__intro">
                Scegli quale profilo tenere per ogni nome: gli altri verranno eliminati insieme ai loro turni e assegnazioni.
            </p>
            {groups.map((group) => (
                <div key={group[0].id} className="tm-duplicates__group">
                    <h3 className="tm-duplicates__name">{group[0].first_name} {group[0].last_name} <span>({group.length} profili)</span></h3>
                    <div className="tm-duplicates__cards">
                        {group.map((doctor) => {
                            const row = activity ? activity[doctor.id] : null;
                            return (
                                <div key={doctor.id} className="tm-duplicates__card">
                                    <div className="tm-duplicates__fields">
                                        <span className="tm-duplicates__id">ID {doctor.id}</span>
                                        <strong>{doctor.email}</strong>
                                        <span>{doctor.phone || 'Nessun telefono'}</span>
                                        <span>{qualLabel(doctor.qualification)} · {aftName(doctor.home_aft)}</span>
                                        {row?.registered && <span>Registrato il {formatDate(row.registered)}</span>}
                                    </div>
                                    <p className={`tm-duplicates__activity${row?.has_activity ? ' has-activity' : ''}`}>
                                        {activity ? activityText(row) : 'Carico il resoconto attività…'}
                                    </p>
                                    <Button
                                        variant="secondary"
                                        disabled={busy || !groupActivityLoaded(group)}
                                        onClick={() => keepDoctor(group, doctor)}
                                    >
                                        Tieni questo
                                    </Button>
                                </div>
                            );
                        })}
                    </div>
                </div>
            ))}
        </Modal>
    );
}
