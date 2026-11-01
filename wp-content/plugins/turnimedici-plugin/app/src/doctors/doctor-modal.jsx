import { useState, useRef, useEffect } from '@wordpress/element';
import { Modal, TextControl, SelectControl, CheckboxControl, Button, Flex, FlexItem } from '@wordpress/components';
import { QUALIFICATIONS, isRuap, isCaTemporaneo, ruapWeeklyHoursFromPatients, corsistaWeeklyHours, hasRuapHourlyQuota, CORSISTA_MAX_PATIENTS } from './constants';
import { createDoctor, updateDoctor, searchEstar } from './api';
import FieldContextMenu from './field-context-menu';

const FIELD_BY_LABEL = { 'Nome': 'first_name', 'Cognome': 'last_name', 'Telefono': 'phone', 'Assistiti': 'patients', 'AFT di appartenenza': 'home_aft', 'Ore settimanali da coprire': 'weekly_hours' };

const GENDER_OPTIONS = [
    { value: '', label: '—' },
    { value: 'female', label: 'Femmina' },
    { value: 'male', label: 'Maschio' },
    { value: 'other', label: 'Altro / non indicato' },
];

export default function DoctorModal({ doctor, afts, facilities, pendingFields, onResolve, onClose, onSaved }) {
    const [data, setData] = useState(doctor);
    const [saving, setSaving] = useState(false);
    const [ctx, setCtx] = useState(null);
    const isNew = !doctor.id;
    const set = (field, value) => setData({ ...data, [field]: value });

    const formRef = useRef(null);

    useEffect(() => {
        const handler = (event) => {
            const el = event.target;
            if (!formRef.current || !formRef.current.contains(el)) return;
            if (!el.matches || !el.matches('input, textarea, select') || el.type === 'date') return;
            event.preventDefault();
            const label = (el.labels?.[0]?.textContent || el.closest('.components-base-control')?.querySelector('label')?.textContent || '').trim();
            setCtx({ x: event.clientX, y: event.clientY, target: el, field: FIELD_BY_LABEL[label] || null });
        };
        document.addEventListener('contextmenu', handler);
        return () => document.removeEventListener('contextmenu', handler);
    }, []);

    const verifyWithEstar = async (field) => {
        const first = (data.first_name || '').trim();
        const last = (data.last_name || '').trim();
        if (!first && !last) {
            window.alert('Inserisci nome e cognome prima di verificare.');
            return;
        }
        try {
            const medici = (await searchEstar(`${first} ${last}`.trim())).medici || [];
            if (medici.length === 0) {
                window.alert(`Nessun medico ESTAR per "${first} ${last}".`);
                return;
            }
            if (medici.length > 1) {
                window.alert(`Trovati troppi risultati (${medici.length}) per "${first} ${last}".`);
                return;
            }
            const m = medici[0];
            const titled = (value) => (value || '').toLowerCase().split(' ').filter(Boolean).map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
            const valueByField = {
                first_name: titled(m.nome),
                last_name: titled(m.cognome),
                phone: m.estar_phone || m.ambulatori?.[0]?.telefono || '',
                patients: Number(m.assistiti) || 0,
                home_aft: parseInt(m.aft_id, 10) || 0,
            };
            if (field === 'home_aft' && !valueByField.home_aft) {
                window.alert(`L'AFT "${m.aft}" non è tra le AFT registrate.`);
                return;
            }
            if (field && field in valueByField) {
                set(field, valueByField[field]);
            } else {
                window.alert(
                    `Dati ESTAR — ${m.cognome} ${m.nome}\n\n` +
                    `Tipologia: ${m.tipologia || '—'}\n` +
                    `AFT: ${m.aft || '—'}\n` +
                    `Assistiti: ${m.assistiti ?? '—'}\n` +
                    `Telefono: ${valueByField.phone || '—'}\n\n` +
                    'Per questo campo ESTAR non fornisce un valore da inserire.'
                );
            }
        } catch (err) {
            window.alert(err.message);
        }
    };

    const calculateWeeklyHours = () => {
        const patients = Number(data.patients) || 0;
        if (patients <= 0) {
            window.alert('Inserisci prima il numero di assistiti.');
            return;
        }
        if (data.qualification === 'ruap_corsista') {
            const quota = corsistaWeeklyHours(patients);
            set('weekly_hours', quota.mandatory);
            window.alert(`Corsista con ${patients} assistiti: ${quota.mandatory} ore/settimana obbligatorie, fino a ${quota.voluntary} facoltative.`);
            return;
        }
        set('weekly_hours', ruapWeeklyHoursFromPatients(patients));
    };

    const aftOptions = [{ value: 0, label: '—' }, ...afts.map((aft) => ({ value: aft.id, label: aft.name }))];
    const facilityLabel = (facility) => facility.aft_name ? `${facility.name} · ${facility.aft_name}` : facility.name;
    const facilityOptions = [
        { value: 0, label: '— Nessuna preferenza —' },
        ...(facilities || []).map((facility) => ({ value: facility.id, label: facilityLabel(facility) })),
    ];

    const periods = data.ca_periods || [];
    const setPeriods = (next) => set('ca_periods', next);
    const updatePeriod = (index, field, value) => setPeriods(periods.map((period, i) => (i === index ? { ...period, [field]: value } : period)));
    const addPeriod = () => setPeriods([...periods, { start: '', end: '' }]);
    const removePeriod = (index) => setPeriods(periods.filter((_, i) => i !== index));

    const save = async () => {
        setSaving(true);
        try {
            const saved = isNew ? await createDoctor(data) : await updateDoctor(data.id, data);
            onSaved(saved, isNew);
        } catch (err) {
            window.alert(err.message);
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal title={isNew ? 'Nuovo medico' : `${data.first_name} ${data.last_name}`.trim()} onRequestClose={onClose}>
            {pendingFields && pendingFields.length > 0 && (
                <div className="tm-pending">
                    <strong className="tm-pending__title">Richieste di modifica in sospeso</strong>
                    {pendingFields.map((field) => (
                        <div key={field.field} className="tm-pending__row">
                            <div className="tm-pending__info">
                                <span className="tm-pending__label">{field.label}</span>
                                <span className="tm-pending__change">{field.current} → <strong>{field.requested}</strong></span>
                            </div>
                            <div className="tm-pending__actions">
                                <Button variant="primary" size="small" onClick={() => onResolve(field.field, 'approve')}>Approva</Button>
                                <Button variant="tertiary" isDestructive size="small" onClick={() => onResolve(field.field, 'reject')}>Rifiuta</Button>
                            </div>
                        </div>
                    ))}
                </div>
            )}
            <div className="tm-doctor-form" ref={formRef}>
                <TextControl label="Nome" value={data.first_name} onChange={(v) => set('first_name', v)} __next40pxDefaultSize __nextHasNoMarginBottom />
                <TextControl label="Cognome" value={data.last_name} onChange={(v) => set('last_name', v)} __next40pxDefaultSize __nextHasNoMarginBottom />
                <TextControl type="email" label="Email" value={data.email} onChange={(v) => set('email', v)} __next40pxDefaultSize __nextHasNoMarginBottom />
                <TextControl type="tel" label="Telefono" value={data.phone} onChange={(v) => set('phone', v)} __next40pxDefaultSize __nextHasNoMarginBottom />
                <SelectControl label="Sesso" value={data.gender || ''} options={GENDER_OPTIONS} onChange={(v) => set('gender', v)} __next40pxDefaultSize __nextHasNoMarginBottom />
                <SelectControl label="Struttura preferita" value={data.preferred_facility || 0} options={facilityOptions} onChange={(v) => set('preferred_facility', parseInt(v, 10) || 0)} __next40pxDefaultSize __nextHasNoMarginBottom />
                <SelectControl label="Qualifica" value={data.qualification} options={QUALIFICATIONS} onChange={(v) => set('qualification', v)} __next40pxDefaultSize __nextHasNoMarginBottom />
                {!isCaTemporaneo(data.qualification) && (
                    <SelectControl label="AFT di appartenenza" value={data.home_aft} options={aftOptions} onChange={(v) => set('home_aft', parseInt(v, 10) || 0)} __next40pxDefaultSize __nextHasNoMarginBottom />
                )}
                {isRuap(data.qualification) && (
                    <TextControl
                        type="number"
                        label="Assistiti"
                        value={data.patients}
                        onChange={(v) => set('patients', parseInt(v, 10) || 0)}
                        help={data.qualification === 'ruap_corsista' && Number(data.patients) > CORSISTA_MAX_PATIENTS
                            ? `Oltre il massimale corsista (${CORSISTA_MAX_PATIENTS} assistiti da ACN): verifica il dato.`
                            : undefined}
                        __next40pxDefaultSize
                        __nextHasNoMarginBottom
                    />
                )}
                <TextControl type="date" label="Inizio impiego come medico" value={data.role_start} onChange={(v) => set('role_start', v)} __next40pxDefaultSize __nextHasNoMarginBottom />
                <TextControl type="number" step={0.5} min={0} label="Ore settimanali da coprire" value={data.weekly_hours} onChange={(v) => set('weekly_hours', parseFloat(v) || 0)} help="Monte ore di riferimento, 0 per volontari e CA. Nel mese in coda vale il quadruplo." __next40pxDefaultSize __nextHasNoMarginBottom />
                <CheckboxControl label="Prima visita completata (dati confermati)" checked={!!data.profile_confirmed} onChange={(v) => set('profile_confirmed', v)} help="Togli la spunta per far ricomparire al medico la finestra di conferma dei dati al prossimo accesso." __nextHasNoMarginBottom />

                {isCaTemporaneo(data.qualification) && (
                    <div className="tm-doctor-form__periods">
                        <span className="tm-doctor-form__label">Periodi di ingaggio</span>
                        {periods.map((period, index) => (
                            <Flex key={index} align="flex-end">
                                <FlexItem isBlock>
                                    <TextControl type="date" label="Dal" value={period.start} onChange={(v) => updatePeriod(index, 'start', v)} __next40pxDefaultSize __nextHasNoMarginBottom />
                                </FlexItem>
                                <FlexItem isBlock>
                                    <TextControl type="date" label="Al" value={period.end} onChange={(v) => updatePeriod(index, 'end', v)} __next40pxDefaultSize __nextHasNoMarginBottom />
                                </FlexItem>
                                <FlexItem>
                                    <Button isDestructive variant="tertiary" onClick={() => removePeriod(index)}>×</Button>
                                </FlexItem>
                            </Flex>
                        ))}
                        <Button variant="secondary" onClick={addPeriod}>+ Periodo</Button>
                    </div>
                )}
            </div>

            <Flex justify="flex-end" style={{ marginTop: 20 }}>
                <FlexItem><Button variant="tertiary" onClick={onClose}>Annulla</Button></FlexItem>
                <FlexItem><Button variant="primary" onClick={save} isBusy={saving} disabled={saving}>Salva</Button></FlexItem>
            </Flex>

            {ctx && (
                <FieldContextMenu
                    {...ctx}
                    onClose={() => setCtx(null)}
                    onVerify={verifyWithEstar}
                    onCalculate={ctx.field === 'weekly_hours' && hasRuapHourlyQuota(data.qualification) ? calculateWeeklyHours : null}
                />
            )}
        </Modal>
    );
}
