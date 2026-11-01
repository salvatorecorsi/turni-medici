import { useState, useEffect } from '@wordpress/element';
import { getProfile, saveProfile } from './api';
import { QUALIFICATIONS, isRuap } from '../doctors/constants';

const GENDER_OPTIONS = [
    { value: '', label: '—' },
    { value: 'female', label: 'Femmina' },
    { value: 'male', label: 'Maschio' },
    { value: 'other', label: 'Altro / non indicato' },
];

export default function ProfileModal({ onClose, onSaved, forceConfirm = false }) {
    const [data, setData] = useState(null);
    const [afts, setAfts] = useState([]);
    const [facilities, setFacilities] = useState([]);
    const [pending, setPending] = useState([]);
    const [mustConfirm, setMustConfirm] = useState(!!forceConfirm);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [done, setDone] = useState(false);

    useEffect(() => {
        getProfile().then((res) => {
            const p = res.profile;
            const pend = p.pending || [];
            const pendingValue = (field, fallback) => {
                const f = pend.find((x) => x.field === field);
                return f ? f.value : fallback;
            };
            setData({
                first_name: p.first_name || '',
                last_name: p.last_name || '',
                email: p.email || '',
                phone: p.phone || '',
                gender: p.gender || '',
                preferred_facility: Number(p.preferred_facility) || 0,
                patients: pendingValue('patients', p.patients || 0),
                qualification: pendingValue('qualification', p.qualification || ''),
                home_aft: pendingValue('home_aft', p.home_aft || 0),
            });
            setAfts(res.afts || []);
            setFacilities(res.facilities || []);
            setPending(pend);
            setMustConfirm(!!forceConfirm || !!p.requires_confirmation);
        }).catch((e) => setError(e.message));
    }, [forceConfirm]);

    const set = (field, value) => { setData((d) => ({ ...d, [field]: value })); setDone(false); };
    const pendingFor = (field) => pending.find((p) => p.field === field);
    const pendingTitle = (field) => {
        const p = pendingFor(field);
        return p ? `In attesa di approvazione · valore attuale: ${p.current}` : undefined;
    };
    const facilityLabel = (facility) => facility.aft_name ? `${facility.name} · ${facility.aft_name}` : facility.name;

    const submit = async () => {
        setSaving(true);
        setError(null);
        try {
            const wasRequired = mustConfirm;
            const res = await saveProfile({ ...data, confirm_profile: wasRequired });
            setPending(res.pending || []);
            setDone(true);
            if (wasRequired) setMustConfirm(false);
            if (onSaved) onSaved({ confirmed: wasRequired, profile: res });
        } catch (e) {
            setError(e.message);
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="tmp-overlay" onClick={mustConfirm ? undefined : onClose}>
            <div className={`tmp-modal${mustConfirm ? ' tmp-modal--locked' : ''}`} onClick={(e) => e.stopPropagation()}>
                <div className="tmp-modal__head">
                    <h2>I tuoi dati</h2>
                    {!mustConfirm && <button type="button" className="tmp-modal__close" onClick={onClose} aria-label="Chiudi">×</button>}
                </div>

                {!data ? (
                    <div className="tmp-modal__body"><p className="tmd-empty">{error || 'Caricamento…'}</p></div>
                ) : (
                    <>
                        <div className="tmp-modal__body">
                            <section className="tmp-group">
                                <h3 className="tmp-group__title">Dati personali</h3>
                                <p className="tmp-group__note">Le modifiche a questi campi sono immediate.</p>
                                <div className="tmp-grid">
                                    <label className="tmp-field"><span>Nome</span><input value={data.first_name} onChange={(e) => set('first_name', e.target.value)} /></label>
                                    <label className="tmp-field"><span>Cognome</span><input value={data.last_name} onChange={(e) => set('last_name', e.target.value)} /></label>
                                    <label className="tmp-field"><span>Email</span><input type="email" value={data.email} onChange={(e) => set('email', e.target.value)} /></label>
                                    <label className="tmp-field"><span>Telefono</span><input type="tel" value={data.phone} onChange={(e) => set('phone', e.target.value)} /></label>
                                    <label className="tmp-field">
                                        <span>Sesso</span>
                                        <select value={data.gender} onChange={(e) => set('gender', e.target.value)}>
                                            {GENDER_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                                        </select>
                                    </label>
                                    <label className="tmp-field tmp-field--wide">
                                        <span>Struttura preferita</span>
                                        <select value={data.preferred_facility} onChange={(e) => set('preferred_facility', Number(e.target.value))}>
                                            <option value={0}>— Nessuna preferenza —</option>
                                            {facilities.map((facility) => <option key={facility.id} value={facility.id}>{facilityLabel(facility)}</option>)}
                                        </select>
                                    </label>
                                </div>
                            </section>

                            <section className="tmp-group">
                                <h3 className="tmp-group__title">Dati professionali</h3>
                                <p className="tmp-group__note">Le modifiche a questi campi richiedono l'approvazione di un amministratore.</p>
                                <div className="tmp-grid">
                                    <label className={`tmp-field${pendingFor('qualification') ? ' is-pending' : ''}`}>
                                        <span>Qualifica</span>
                                        <select value={data.qualification} title={pendingTitle('qualification')} onChange={(e) => set('qualification', e.target.value)}>
                                            <option value="">—</option>
                                            {QUALIFICATIONS.map((q) => <option key={q.value} value={q.value}>{q.label}</option>)}
                                        </select>
                                    </label>
                                    {data.qualification !== 'ca_temporaneo' && (
                                        <label className={`tmp-field${pendingFor('home_aft') ? ' is-pending' : ''}`}>
                                            <span>AFT di appartenenza</span>
                                            <select value={data.home_aft} title={pendingTitle('home_aft')} onChange={(e) => set('home_aft', Number(e.target.value))}>
                                                <option value={0}>—</option>
                                                {afts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                                            </select>
                                        </label>
                                    )}
                                    {isRuap(data.qualification) && (
                                        <label className={`tmp-field${pendingFor('patients') ? ' is-pending' : ''}`}>
                                            <span>Assistiti</span>
                                            <input type="number" min="0" value={data.patients} title={pendingTitle('patients')} onChange={(e) => set('patients', Number(e.target.value))} />
                                        </label>
                                    )}
                                </div>
                            </section>

                            {error && <p className="tmp-error">{error}</p>}
                            {done && <p className="tmp-ok">{pending.length ? 'Dati salvati. Le modifiche ai dati professionali restano in attesa di approvazione.' : 'Dati salvati.'}</p>}
                        </div>

                        <div className="tmp-modal__foot">
                            {!mustConfirm && <button type="button" className="tmp-btn tmp-btn--ghost" onClick={onClose}>Annulla</button>}
                            <button type="button" className="tmp-btn tmp-btn--primary" disabled={saving} onClick={submit}>
                                {saving ? 'Salvataggio…' : (mustConfirm ? 'Salva e conferma' : 'Salva')}
                            </button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
