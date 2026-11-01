import { useState, useEffect, createPortal } from '@wordpress/element';
import { Spinner, Button, Notice, SearchControl, SelectControl, ComboboxControl, CheckboxControl, Dropdown, MenuGroup, MenuItem } from '@wordpress/components';
import { getDoctors, getAfts, getFacilities, getRanking, getChangeRequests, resolveChangeRequest } from './api';
import DoctorRow from './doctor-row';
import DoctorModal from './doctor-modal';
import { findDuplicateGroups, DuplicateDoctorsModal } from './duplicates-modal';
import ImportControls from './doctors-import';
import CaRosterBar from './ca-roster-bar';
import EstarPanel from './estar-panel';
import { isCaTemporaneo, isCaValidToday, qualLabel, isRuap } from './constants';

const emptyDoctor = () => ({
    id: null,
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    gender: '',
    qualification: 'mmg',
    home_aft: Number((window.tmDoctors || {}).coordinatedAft) || 0,
    patients: 0,
    role_start: '',
    weekly_hours: 0,
    ca_periods: [],
    preferred_facility: 0,
    magic_url: '',
});

const SUB_OPTIONS = {
    '': [
        { value: 'mmg', label: 'MMG' },
        { value: 'ruap_volontario', label: 'RUAP Volontario' },
        { value: 'ruap_vincolato', label: 'RUAP Vincolato' },
        { value: 'ruap_corsista', label: 'Corsista RUAP' },
        { value: 'ca_titolare', label: 'CA Titolare' },
        { value: 'ca_temporaneo', label: 'CA Temporaneo attivo' },
        { value: 'scaduto', label: 'CA Scaduto' },
        { value: 'none', label: 'Da configurare' },
    ],
    mmg: [],
    ruap: [
        { value: 'ruap_volontario', label: 'Volontario' },
        { value: 'ruap_vincolato', label: 'Vincolato' },
        { value: 'ruap_corsista', label: 'Corsista' },
    ],
    ca: [
        { value: 'ca_titolare', label: 'Titolare' },
        { value: 'ca_temporaneo', label: 'Temporaneo attivo' },
        { value: 'scaduto', label: 'Scaduto' },
    ],
};

const defaultSubs = (base) => (SUB_OPTIONS[base] || []).map((option) => option.value).filter((value) => value !== 'scaduto');

const KNOWN_QUALS = ['mmg', 'ruap_volontario', 'ruap_vincolato', 'ruap_corsista', 'ca_titolare', 'ca_temporaneo'];
const normalizeText = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

const doctorSubType = (doctor) => {
    if (doctor.qualification === 'ca_temporaneo') return isCaValidToday(doctor) ? 'ca_temporaneo' : 'scaduto';
    return KNOWN_QUALS.includes(doctor.qualification) ? doctor.qualification : 'none';
};

export default function DoctorsApp() {
    const config = window.tmDoctors || {};
    const canEditAll = !!config.canEditAll;
    const [doctors, setDoctors] = useState(null);
    const [afts, setAfts] = useState([]);
    const [facilities, setFacilities] = useState([]);
    const [ranking, setRanking] = useState(null);
    const [error, setError] = useState(null);
    const [search, setSearch] = useState('');
    const [base, setBase] = useState('');
    const [subs, setSubs] = useState(() => defaultSubs(''));
    const [sort, setSort] = useState({ field: 'ranking', dir: 'asc' });
    const [aftFilter, setAftFilter] = useState(0);
    const [editing, setEditing] = useState(null);
    const [duplicatesOpen, setDuplicatesOpen] = useState(false);
    const [showEstar, setShowEstar] = useState(false);
    const [requests, setRequests] = useState([]);

    useEffect(() => {
        Promise.all([getDoctors(), getAfts(), getFacilities(), getRanking(), canEditAll ? getChangeRequests() : Promise.resolve([])])
            .then(([loadedDoctors, loadedAfts, loadedFacilities, loadedRanking, loadedRequests]) => {
                setDoctors(loadedDoctors);
                setAfts(loadedAfts);
                setFacilities(loadedFacilities);
                setRanking(loadedRanking);
                setRequests(loadedRequests);
            })
            .catch((err) => setError(err.message));
    }, []);

    if (error) {
        return <Notice status="error" isDismissible={false}>{error}</Notice>;
    }
    if (doctors === null || ranking === null) {
        return <Spinner />;
    }

    const aftName = (id) => (afts.find((aft) => aft.id === id) || {}).name || '—';
    const onSaved = (saved, isNew) => {
        setDoctors(isNew ? [saved, ...doctors] : doctors.map((doctor) => (doctor.id === saved.id ? saved : doctor)));
        setEditing(null);
    };
    const setMagicUrl = (id, magic_url) => setDoctors(doctors.map((doctor) => (doctor.id === id ? { ...doctor, magic_url } : doctor)));
    const setInviteSent = (id, result) => setDoctors(doctors.map((doctor) => (
        doctor.id === id
            ? {
                ...doctor,
                magic_url: result.magic_url || doctor.magic_url,
                invite_sent_at: result.invite_sent_at,
                invite_sent_label: result.invite_sent_label,
            }
            : doctor
    )));

    const requestsFor = (doctorId) => (requests.find((r) => r.doctor_id === doctorId) || {}).fields || [];
    const resolveRequest = async (doctorId, field, action) => {
        const res = await resolveChangeRequest(doctorId, field, action);
        setRequests((current) => current
            .map((r) => (r.doctor_id === doctorId ? { ...r, fields: res.fields } : r))
            .filter((r) => r.fields.length > 0));
        if (action === 'approve') {
            getDoctors().then(setDoctors);
        }
    };

    const changeBase = (value) => {
        setBase(value);
        setSubs(defaultSubs(value));
    };
    const toggleSub = (value, on) => setSubs(on ? [...subs, value] : subs.filter((sub) => sub !== value));

    const matchesQualification = (doctor) => {
        if (base === 'mmg') return doctor.qualification === 'mmg';
        if (base === 'ruap' && !doctor.qualification.startsWith('ruap_')) return false;
        if (base === 'ca' && !doctor.qualification.startsWith('ca_')) return false;

        const options = SUB_OPTIONS[base] || [];
        if (!options.length) return true;
        return subs.includes(doctorSubType(doctor));
    };

    const compareAlpha = (a, b) =>
        (a.last_name || '').localeCompare(b.last_name || '') || (a.first_name || '').localeCompare(b.first_name || '');
    const compareRanking = (a, b) =>
        (Number(a.ranking_position) || Number.MAX_SAFE_INTEGER) - (Number(b.ranking_position) || Number.MAX_SAFE_INTEGER)
        || compareAlpha(a, b);

    const fieldValue = (doctor, field) => {
        switch (field) {
            case 'id': return doctor.id;
            case 'patients': return isRuap(doctor.qualification) ? doctor.patients : -1;
            case 'first_name': return normalizeText(doctor.first_name);
            case 'last_name': return normalizeText(doctor.last_name);
            case 'email': return normalizeText(doctor.email);
            case 'phone': return normalizeText(doctor.phone);
            case 'qualification': return normalizeText(qualLabel(doctor.qualification));
            case 'aft': return normalizeText(aftName(doctor.home_aft));
            default: return '';
        }
    };
    const compareField = (a, b) => {
        const va = fieldValue(a, sort.field);
        const vb = fieldValue(b, sort.field);
        let cmp = typeof va === 'number' ? va - vb : String(va).localeCompare(String(vb));
        if (cmp === 0) cmp = compareAlpha(a, b);
        return sort.dir === 'desc' ? -cmp : cmp;
    };
    const sorter = sort.field === 'ranking' ? compareRanking : sort.field === 'alpha' ? compareAlpha : compareField;
    const sortByCol = (field) => setSort((s) => ({ field, dir: s.field === field && s.dir === 'asc' ? 'desc' : 'asc' }));
    const th = (field, label) => (
        <th scope="col" className={`manage-column tm-sortable${sort.field === field ? ' is-sorted' : ''}`} onClick={() => sortByCol(field)} aria-sort={sort.field === field ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}>
            {label}<span className="tm-sortarrow" aria-hidden="true">{sort.field === field ? (sort.dir === 'asc' ? ' ↑' : ' ↓') : ' ↕'}</span>
        </th>
    );

    const duplicateGroups = canEditAll ? findDuplicateGroups(doctors) : [];
    const onDuplicatesResolved = (removedIds) => {
        setDoctors((current) => current.filter((doctor) => !removedIds.includes(doctor.id)));
    };

    const term = normalizeText(search.trim());
    const doctorSearch = (doctor) => normalizeText(`${doctor.first_name || ''} ${doctor.last_name || ''} ${doctor.phone || ''} ${doctor.email || ''}`);
    const visible = doctors
        .filter((doctor) => {
            if (!matchesQualification(doctor)) return false;
            if (aftFilter && doctor.home_aft !== aftFilter && !isCaTemporaneo(doctor.qualification)) return false;
            if (term && !doctorSearch(doctor).includes(term)) return false;
            return true;
        })
        .sort(sorter);

    const count = (predicate) => doctors.filter(predicate).length;
    const aftFilterOptions = [
        { value: '0', label: `Tutte le AFT (${doctors.length})` },
        ...afts.map((aft) => ({ value: String(aft.id), label: `${aft.name} (${count((doctor) => doctor.home_aft === aft.id)})` })),
    ];
    const baseOptions = [
        { value: '', label: `Tutte (${doctors.length})` },
        { value: 'mmg', label: `MMG (${count((d) => d.qualification === 'mmg')})` },
        { value: 'ruap', label: `RUAP (${count((d) => d.qualification.startsWith('ruap_'))})` },
        { value: 'ca', label: `CA (${count((d) => d.qualification.startsWith('ca_'))})` },
    ];
    const subCount = (sub) => {
        if (sub === 'none') return count((d) => doctorSubType(d) === 'none');
        if (sub === 'scaduto') return count((d) => d.qualification === 'ca_temporaneo' && !isCaValidToday(d));
        if (sub === 'ca_temporaneo') return count((d) => d.qualification === 'ca_temporaneo' && isCaValidToday(d));
        return count((d) => d.qualification === sub);
    };

    return (
        <div className="tm-doctors">
            <div className="tm-doctors__header">
            {canEditAll && <CaRosterBar onChanged={() => getDoctors().then(setDoctors)} />}
            <div className="tm-doctors__toolbar">
                <SearchControl className="tm-doctors__search" value={search} onChange={setSearch} placeholder="Cerca per nome, telefono o email" __next40pxDefaultSize __nextHasNoMarginBottom />
                <Dropdown
                    renderToggle={({ isOpen, onToggle }) => (
                        <Button variant="primary" onClick={onToggle} aria-expanded={isOpen}>+ Nuovo medico</Button>
                    )}
                    renderContent={({ onClose }) => (
                        <MenuGroup>
                            <MenuItem onClick={() => { setEditing(emptyDoctor()); onClose(); }}>Inserisci manualmente</MenuItem>
                            {canEditAll && <MenuItem disabled={window.tmDoctors?.demoMode} onClick={() => { setShowEstar(true); onClose(); }}>Importa da ESTAR</MenuItem>}
                        </MenuGroup>
                    )}
                />
                {requests.length > 0 && (
                    <Button variant="secondary" className="tm-doctors__requests" onClick={() => {
                        const first = doctors.find((doctor) => doctor.id === requests[0].doctor_id);
                        if (first) setEditing(first);
                    }}>
                        <span className="tm-doctors__requests-dot" />
                        {requests.length} richiest{requests.length === 1 ? 'a' : 'e'} in sospeso
                    </Button>
                )}
            </div>

            <div className="tm-doctors__toolbar tm-doctors__toolbar--filters">
                <ComboboxControl className="tm-doctors__aft" value={String(aftFilter)} options={aftFilterOptions} onChange={(value) => setAftFilter(parseInt(value, 10) || 0)} placeholder="Filtra per AFT" __next40pxDefaultSize __nextHasNoMarginBottom />
                <SelectControl value={base} options={baseOptions} onChange={changeBase} __next40pxDefaultSize __nextHasNoMarginBottom />
                {(SUB_OPTIONS[base] || []).map((option) => (
                    <CheckboxControl
                        key={option.value}
                        label={`${option.label} (${subCount(option.value)})`}
                        checked={subs.includes(option.value)}
                        onChange={(on) => toggleSub(option.value, on)}
                        __nextHasNoMarginBottom
                    />
                ))}
            </div>
            </div>

            {duplicateGroups.length > 0 && (
                <div className="tm-duplicates-notice">
                    <span>
                        <strong>Possibili medici duplicati:</strong>{' '}
                        {duplicateGroups.map((group) => `${group[0].first_name} ${group[0].last_name} (${group.length})`).join(', ')}
                    </span>
                    <Button variant="secondary" size="small" onClick={() => setDuplicatesOpen(true)}>Rivedi e risolvi</Button>
                </div>
            )}

            <table className="wp-list-table widefat striped tm-doctors__table">
                <thead>
                    <tr>
                        {th('id', 'ID')}
                        {th('first_name', 'Nome')}
                        {th('last_name', 'Cognome')}
                        {th('email', 'Email')}
                        {th('phone', 'Telefono')}
                        {th('qualification', 'Qualifica')}
                        {th('aft', 'AFT')}
                        {th('patients', 'Assistiti')}
                        <th scope="col" className="manage-column">Accesso</th>
                    </tr>
                </thead>
                <tbody>
                    {visible.map((doctor) => (
                        <DoctorRow
                            key={doctor.id}
                            doctor={doctor}
                            aftName={aftName(doctor.home_aft)}
                            onEdit={setEditing}
                            onMagicUrl={(url) => setMagicUrl(doctor.id, url)}
                            onInvited={(result) => setInviteSent(doctor.id, result)}
                        />
                    ))}
                    {visible.length === 0 && (
                        <tr><td colSpan={9} className="tm-doctors__empty">Nessun medico per i filtri attivi.</td></tr>
                    )}
                </tbody>
            </table>

            {duplicatesOpen && duplicateGroups.length > 0 && (
                <DuplicateDoctorsModal
                    groups={duplicateGroups}
                    afts={afts}
                    onClose={() => setDuplicatesOpen(false)}
                    onResolved={onDuplicatesResolved}
                />
            )}

            {editing && (
                <DoctorModal
                    doctor={editing}
                    afts={afts}
                    facilities={facilities}
                    pendingFields={requestsFor(editing.id)}
                    onResolve={(field, action) => resolveRequest(editing.id, field, action)}
                    onClose={() => setEditing(null)}
                    onSaved={onSaved}
                />
            )}

            {(() => {
                const node = document.getElementById('tm-estar-toggle');
                return node ? createPortal(
                    <div className="tm-doctors__head-actions">
                        {canEditAll && (
                            <>
                                <ImportControls afts={afts} onImported={() => getDoctors().then(setDoctors)} />
                                <Button
                                    variant={showEstar ? 'primary' : 'secondary'}
                                    disabled={window.tmDoctors?.demoMode}
                                    onClick={() => setShowEstar((value) => !value)}
                                >
                                    <img src={(window.tmDoctors || {}).faviconUrl} alt="" className="tm-estar-favicon" />
                                    Ricerca ESTAR
                                </Button>
                            </>
                        )}
                    </div>,
                    node
                ) : null;
            })()}

            {showEstar && (
                <EstarPanel
                    onClose={() => setShowEstar(false)}
                    onImported={(doctor, action) => {
                        setDoctors((current) =>
                            action === 'created' ? [doctor, ...current] : current.map((d) => (d.id === doctor.id ? doctor : d))
                        );
                        getAfts().then(setAfts);
                    }}
                />
            )}
        </div>
    );
}
