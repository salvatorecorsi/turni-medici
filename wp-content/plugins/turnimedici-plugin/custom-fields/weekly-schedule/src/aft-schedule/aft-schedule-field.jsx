import { useState, useEffect, useRef } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { TabPanel, Button } from '@wordpress/components';
import { DndContext, closestCenter } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy, arrayMove } from '@dnd-kit/sortable';
import AftDoctorRow from './doctor-row';
import apiFetch from '@wordpress/api-fetch';
import AmbulatoryScheduleEditor from './ambulatory-schedule-editor';
import AddDoctorsModal from './add-doctors-modal';

const DEFAULT_CONFIG = { dayStart: '08:00', dayEnd: '19:00', bands: [['09:00', '13:00'], ['14:00', '19:00']] };
const PALETTE = ['#3f6cb5', '#2f8f84', '#7d5fc6', '#c14a86', '#d98a2b', '#2f8fb0', '#4f9e54', '#b35cc0', '#5b6b7d', '#8a6d3b', '#6a8a3a', '#6b6bbf', '#a8527f', '#a66433'];
const PALETTE_OPTIONS = PALETTE.map((color) => ({ name: color, color }));
const toMin = (s) => { const m = String(s || '').match(/^(\d{1,2}):(\d{2})$/); return m ? Number(m[1]) * 60 + Number(m[2]) : 0; };

export default function AftScheduleField() {
    const postId = useSelect((s) => (s('core/editor') ? s('core/editor').getCurrentPostId() : 0), []);
    const [ctx, setCtx] = useState(null);
    const [config, setConfig] = useState(DEFAULT_CONFIG);
    const [doctors, setDoctors] = useState([]);
    const [showAdd, setShowAdd] = useState(false);
    const [openDocs, setOpenDocs] = useState(() => new Set());
    const [email, setEmail] = useState('');
    const [emailMsg, setEmailMsg] = useState('');
    const [importOpen, setImportOpen] = useState(false);
    const [importText, setImportText] = useState('');
    const [importMsg, setImportMsg] = useState('');
    const [printing, setPrinting] = useState(false);
    const previewRef = useRef(null);
    const timers = useRef({});

    useEffect(() => {
        if (!postId) return;
        apiFetch({ path: `/turnimedici/v1/aft/${postId}/board-context` }).then((c) => {
            setCtx(c);
            setConfig({ ...DEFAULT_CONFIG, ...(c.config || {}) });
            setDoctors(c.doctors || []);
        }).catch(() => setCtx({ doctors: [], ambulatori: [], allDoctors: [] }));
    }, [postId]);

    const ambulatori = ctx ? ctx.ambulatori || [] : [];

    const buildPayload = () => {
        const used = {};
        const shifts = [];
        doctors.forEach((d) => (d.schedule || []).forEach((s) => {
            if (!s.start || !s.end) return;
            shifts.push({ doctor: String(d.id), loc: String(s.amb), day: s.day, start: s.start, end: s.end, booking: s.booking === 'libero' ? 'libero' : 'appuntamento' });
            used[d.id] = true;
        }));
        return {
            meta: { dayStart: config.dayStart, dayEnd: config.dayEnd, bands: config.bands },
            locations: ambulatori.map((a) => ({ id: String(a.id), label: a.label, short: a.short, address: a.address || '' })),
            doctors: doctors.filter((d) => used[d.id]).map((d) => ({ id: String(d.id), name: d.name, color: d.color })),
            shifts,
        };
    };

    const stats = (() => {
        const bands = (config.bands || []).map((b) => [toMin(b[0]), toMin(b[1])]).filter((b) => b[1] > b[0]);
        let target = 0, covered = 0;
        ['lun', 'mar', 'mer', 'gio', 'ven'].forEach((day) => {
            const ds = [];
            doctors.forEach((d) => (d.schedule || []).forEach((s) => { if (s.day === day && s.start && s.end) ds.push([toMin(s.start), toMin(s.end)]); }));
            bands.forEach(([a, b]) => { target += b - a; for (let t = a; t < b; t += 15) if (ds.some(([x, y]) => x <= t && y > t)) covered += 15; });
        });
        return { target, covered, gap: target - covered, pct: target ? Math.round((covered / target) * 100) : 0 };
    })();

    const previewKey = JSON.stringify({ config, doctors: doctors.map((d) => [d.id, d.color, d.schedule]), ambulatori });
    useEffect(() => {
        if (!previewRef.current || !window.TMBoard) return;
        try { window.TMBoard.render(previewRef.current, buildPayload(), { fit: 'inline', laneH: 15 }); } catch (e) { /* noop */ }
    }, [previewKey]);

    const saveConfig = (next) => {
        setConfig(next);
        clearTimeout(timers.current.cfg);
        timers.current.cfg = setTimeout(() => { apiFetch({ path: `/turnimedici/v1/aft/${postId}/config`, method: 'POST', data: { config: next } }).catch(() => {}); }, 500);
    };
    const setBand = (bi, pos, val) => { const bands = config.bands.map((b) => b.slice()); if (!bands[bi]) bands[bi] = ['', '']; bands[bi][pos] = val; saveConfig({ ...config, bands }); };

    const saveSchedule = (docId, schedule) => {
        setDoctors((prev) => prev.map((d) => (d.id === docId ? { ...d, schedule } : d)));
        clearTimeout(timers.current[docId]);
        timers.current[docId] = setTimeout(() => { apiFetch({ path: `/turnimedici/v1/aft/${postId}/doctor/${docId}/schedule`, method: 'POST', data: { schedule } }).catch(() => {}); }, 600);
    };

    const addDoctors = async (newIds) => {
        setShowAdd(false);
        for (const id of newIds) {
            try {
                const doc = await apiFetch({ path: `/turnimedici/v1/aft/${postId}/doctor/${id}/member`, method: 'POST', data: { member: true } });
                setDoctors((prev) => (prev.some((d) => d.id === doc.id) ? prev : [...prev, doc]));
            } catch (e) {
                window.alert(e.message);
            }
        }
    };
    const toggleDoc = (docId) => setOpenDocs((prev) => {
        const next = new Set(prev);
        if (next.has(docId)) next.delete(docId); else next.add(docId);
        return next;
    });

    const saveOrder = (ids) => {
        clearTimeout(timers.current.order);
        timers.current.order = setTimeout(() => { apiFetch({ path: `/turnimedici/v1/aft/${postId}/order`, method: 'POST', data: { order: ids } }).catch(() => {}); }, 400);
    };
    const onDragEnd = ({ active, over }) => {
        if (!over || active.id === over.id) return;
        setDoctors((prev) => {
            const ids = prev.map((d) => d.id);
            const next = arrayMove(prev, ids.indexOf(active.id), ids.indexOf(over.id));
            saveOrder(next.map((d) => d.id));
            return next;
        });
    };
    const setColor = (docId, color) => {
        setDoctors((prev) => prev.map((d) => (d.id === docId ? { ...d, color } : d)));
        clearTimeout(timers.current['color' + docId]);
        timers.current['color' + docId] = setTimeout(() => { apiFetch({ path: `/turnimedici/v1/doctors/${docId}/color`, method: 'POST', data: { color } }).catch(() => {}); }, 400);
    };

    const removeDoctor = (docId) => {
        if (!window.confirm('Togliere il medico da questa AFT? I suoi orari restano salvati su di lui.')) return;
        apiFetch({ path: `/turnimedici/v1/aft/${postId}/doctor/${docId}/member`, method: 'POST', data: { member: false } }).then(() => {
            setDoctors((prev) => prev.filter((d) => d.id !== docId));
        }).catch((e) => window.alert(e.message));
    };

    const printBoard = async () => {
        if (!ctx) return;
        setPrinting(true);
        try {
            const fd = new FormData();
            fd.append('action', 'tm_aft_board_print');
            fd.append('aft', ctx.aftId);
            fd.append('_wpnonce', ctx.printNonce);
            fd.append('config', JSON.stringify(config));
            const res = await fetch(ctx.printUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
            const html = await res.text();
            const old = document.getElementById('tm-aft-print-frame');
            if (old) old.remove();
            const iframe = document.createElement('iframe');
            iframe.id = 'tm-aft-print-frame';
            iframe.style.cssText = 'position:fixed;left:-9999px;top:0;width:1123px;height:794px;border:0;';
            iframe.onload = () => setTimeout(() => { try { iframe.contentWindow.focus(); iframe.contentWindow.print(); } catch (e) { /* noop */ } }, 400);
            document.body.appendChild(iframe);
            iframe.srcdoc = html;
        } catch (e) {
            window.alert('Stampa non riuscita: ' + e.message);
        } finally { setPrinting(false); }
    };

    const sendEmail = () => {
        setEmailMsg('');
        apiFetch({ path: `/turnimedici/v1/aft/${postId}/email`, method: 'POST', data: { email } })
            .then(() => { setEmailMsg('Inviato a ' + email + '.'); setEmail(''); })
            .catch((e) => setEmailMsg(e.message || 'Invio non riuscito.'));
    };

    const doImport = () => {
        let parsed;
        try { parsed = JSON.parse(importText.replace(/,(\s*[}\]])/g, '$1')); } catch (e) { setImportMsg('JSON non valido: ' + e.message); return; }
        const slugName = {};
        (parsed.doctors || []).forEach((d) => { slugName[d.id] = d.name; });
        const bySurname = {};
        doctors.forEach((d) => { bySurname[String(d.name).trim().toLowerCase()] = d; });
        const ambBySlug = {};
        ambulatori.forEach((a) => { ambBySlug[String(a.short).toLowerCase()] = a.id; ambBySlug[String(a.label).trim().toLowerCase()] = a.id; });
        const jlocById = {};
        (parsed.locations || []).forEach((l) => { jlocById[l.id] = l; });
        const unmatchedDoc = new Set(), unmatchedAmb = new Set();
        const perDoctor = {};
        (parsed.shifts || []).forEach((s) => {
            const surname = slugName[s.doctor] || s.doctor;
            const doc = bySurname[String(surname).trim().toLowerCase()];
            if (!doc) { unmatchedDoc.add(surname); return; }
            const jloc = jlocById[s.loc] || {};
            const ambId = ambBySlug[String(jloc.short || '').toLowerCase()] || ambBySlug[String(jloc.label || '').trim().toLowerCase()];
            if (!ambId) { unmatchedAmb.add(jloc.label || s.loc); return; }
            if (!perDoctor[doc.id]) perDoctor[doc.id] = [];
            perDoctor[doc.id].push({ amb: ambId, day: s.day, start: String(s.start).replace(/^(\d):/, '0$1:'), end: String(s.end).replace(/^(\d):/, '0$1:'), booking: s.booking === 'libero' ? 'libero' : 'appuntamento' });
        });
        Object.keys(perDoctor).forEach((id) => saveSchedule(Number(id), perDoctor[id]));
        if (parsed.meta) saveConfig({ ...DEFAULT_CONFIG, ...(parsed.meta.dayStart ? { dayStart: parsed.meta.dayStart } : {}), ...(parsed.meta.dayEnd ? { dayEnd: parsed.meta.dayEnd } : {}), ...(parsed.meta.bands ? { bands: parsed.meta.bands } : {}) });
        setImportMsg('Importati orari per ' + Object.keys(perDoctor).length + ' medici.'
            + (unmatchedDoc.size ? ' Medici non in AFT: ' + [...unmatchedDoc].join(', ') + '.' : '')
            + (unmatchedAmb.size ? ' Ambulatori non trovati (crea i CPT con la sigla giusta): ' + [...unmatchedAmb].join(', ') + '.' : ''));
    };

    if (!ctx) return <p className="tm-ao-hint">Caricamento…</p>;

    return (
        <div className="tm-aftorari">
            <TabPanel className="tm-ao-tabs" tabs={[
                { name: 'dash', title: 'Dashboard' },
                { name: 'medici', title: 'Medici' },
                { name: 'extra', title: 'Extra' },
            ]}>
                {(tab) => {
                    if (tab.name === 'dash') return (
                        <div>
                            <div className="tm-ao-section">
                                <label className="tm-options__label">Orario di copertura dell'AFT</label>
                                <p className="tm-ao-hint">L'orario che l'AFT deve garantire. I buchi dentro queste fasce sono le ore scoperte.</p>
                                <div className="tm-ao-config">
                                    <div className="tm-options__group"><label className="tm-options__label">Asse — inizio</label><input type="time" step={300} value={config.dayStart} onChange={(e) => saveConfig({ ...config, dayStart: e.target.value })} /></div>
                                    <div className="tm-options__group"><label className="tm-options__label">Asse — fine</label><input type="time" step={300} value={config.dayEnd} onChange={(e) => saveConfig({ ...config, dayEnd: e.target.value })} /></div>
                                    <div className="tm-options__group"><label className="tm-options__label">Copertura mattina</label><div className="tm-slots__row"><input type="time" step={300} value={(config.bands[0] || [])[0] || ''} onChange={(e) => setBand(0, 0, e.target.value)} /><span className="tm-slots__dash">–</span><input type="time" step={300} value={(config.bands[0] || [])[1] || ''} onChange={(e) => setBand(0, 1, e.target.value)} /></div></div>
                                    <div className="tm-options__group"><label className="tm-options__label">Copertura pomeriggio</label><div className="tm-slots__row"><input type="time" step={300} value={(config.bands[1] || [])[0] || ''} onChange={(e) => setBand(1, 0, e.target.value)} /><span className="tm-slots__dash">–</span><input type="time" step={300} value={(config.bands[1] || [])[1] || ''} onChange={(e) => setBand(1, 1, e.target.value)} /></div></div>
                                </div>
                            </div>
                            <div className="tm-ao-section">
                                <div className="tm-ao-cov">
                                    <div className="tm-ao-cov__head"><strong>Copertura AFT: {stats.pct}%</strong><span>{(stats.covered / 60).toFixed(1)}h su {(stats.target / 60).toFixed(1)}h · <b className={stats.gap ? 'is-gap' : ''}>{(stats.gap / 60).toFixed(1)}h scoperte</b></span></div>
                                    <div className="tm-ao-cov__bar"><span style={{ width: stats.pct + '%' }} /></div>
                                </div>
                                <div ref={previewRef} className="tm-ao-boardpreview" />
                            </div>
                        </div>
                    );

                    if (tab.name === 'medici') return (
                        <div className="tm-assign">
                            <div className="tm-assign__toolbar">
                                <Button variant="primary" onClick={() => setShowAdd(true)}>+ Aggiungi medici</Button>
                            </div>
                            {showAdd && (
                                <AddDoctorsModal assigned={doctors.map((d) => d.id)} onAdd={addDoctors} onClose={() => setShowAdd(false)} />
                            )}
                            {doctors.length === 0 ? (
                                <p className="tm-assign__empty">Nessun medico in questa AFT. Aggiungili con il bottone qui sopra.</p>
                            ) : (
                                <DndContext collisionDetection={closestCenter} onDragEnd={onDragEnd}>
                                    <SortableContext items={doctors.map((d) => d.id)} strategy={verticalListSortingStrategy}>
                                        <ul className="tm-assign__list">
                                            {doctors.map((doc, i) => (
                                                <AftDoctorRow
                                                    key={doc.id}
                                                    doctor={doc}
                                                    color={doc.color || PALETTE[i % PALETTE.length]}
                                                    palette={PALETTE_OPTIONS}
                                                    isOpen={openDocs.has(doc.id)}
                                                    ambulatori={ambulatori}
                                                    onToggle={() => toggleDoc(doc.id)}
                                                    onColor={(value) => setColor(doc.id, value)}
                                                    onRemove={() => removeDoctor(doc.id)}
                                                    onSchedule={(sched) => saveSchedule(doc.id, sched)}
                                                />
                                            ))}
                                        </ul>
                                    </SortableContext>
                                </DndContext>
                            )}
                        </div>
                    );

                    return (
                        <div>
                            <div className="tm-ao-section">
                                <label className="tm-options__label">Stampa</label>
                                <div className="tm-ao-addrow">
                                    <Button variant="primary" isBusy={printing} disabled={printing || !stats.target} onClick={printBoard}>Stampa prospetto</Button>
                                </div>
                            </div>
                            <div className="tm-ao-section">
                                <label className="tm-options__label">Invia via email</label>
                                <p className="tm-ao-hint">{ctx.demoMode ? 'Invio email disabilitato nella demo. Puoi usare il link diretto al prospetto.' : 'Invia un link al prospetto sempre aggiornato (stampabile in PDF dal browser).'}</p>
                                <div className="tm-ao-addrow">
                                    <input type="email" placeholder="destinatario@example.invalid" value={email} disabled={ctx.demoMode} onChange={(e) => setEmail(e.target.value)} />
                                    <Button variant="secondary" disabled={!email || ctx.demoMode} onClick={sendEmail}>Invia link</Button>
                                </div>
                                {emailMsg && <p className="tm-ao-hint">{emailMsg}</p>}
                                {ctx.prospettoUrl && <p className="tm-ao-hint">Link diretto: <a href={ctx.prospettoUrl} target="_blank" rel="noopener">{ctx.prospettoUrl}</a></p>}
                            </div>
                            <div className="tm-ao-section">
                                <div className="tm-ao-addrow">
                                    <label className="tm-options__label">Importa da JSON</label>
                                    <Button variant="tertiary" onClick={() => setImportOpen((o) => !o)}>{importOpen ? 'chiudi' : 'apri'}</Button>
                                </div>
                                {importOpen && (
                                    <div className="tm-ao-import">
                                        <p className="tm-ao-hint">Medici associati per cognome, ambulatori per sigla (crea prima i CPT ambulatorio). Scrive gli orari sui singoli medici.</p>
                                        <textarea rows={6} value={importText} onChange={(e) => setImportText(e.target.value)} placeholder='{ "meta": {...}, "locations": [...], "doctors": [...], "shifts": [...] }' />
                                        <div className="tm-ao-import__foot"><Button variant="primary" disabled={!importText.trim()} onClick={doImport}>Importa</Button>{importMsg && <span className="tm-ao-hint">{importMsg}</span>}</div>
                                    </div>
                                )}
                            </div>
                        </div>
                    );
                }}
            </TabPanel>
        </div>
    );
}
