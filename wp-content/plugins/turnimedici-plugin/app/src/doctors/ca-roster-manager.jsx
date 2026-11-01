import { useState, useEffect, useRef, createPortal } from '@wordpress/element';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';

const config = window.tmDoctors || {};

async function api(path, options = {}) {
    const response = await fetch(config.restUrl + path, {
        ...options,
        headers: { 'X-WP-Nonce': config.nonce, ...(options.headers || {}) },
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data?.message || 'Errore di rete');
    return data;
}

const keptPeriodsLabel = (count) => `conserva ${count} ${count === 1 ? 'contratto precedente' : 'contratti precedenti'}`;

function RosterContextMenu({ x, y, roster, onClose, onAction }) {
    useEffect(() => {
        const close = () => onClose();
        const onKey = (event) => { if (event.key === 'Escape') onClose(); };
        window.addEventListener('click', close);
        window.addEventListener('keydown', onKey);
        window.addEventListener('scroll', close, true);
        return () => {
            window.removeEventListener('click', close);
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('scroll', close, true);
        };
    }, [onClose]);

    const item = (label, action) => (
        <li><button type="button" onClick={() => { onClose(); onAction(action, roster); }}>{label}</button></li>
    );
    const scanned = roster.status === 'scanned';

    return createPortal(
        <ul className="tm-fieldctx" style={{ left: x, top: y }} onClick={(event) => event.stopPropagation()} onContextMenu={(event) => event.preventDefault()}>
            {scanned && item('Aggiungi gli slot orari (cumulabile)', 'apply-contracts')}
            {scanned && !roster.active && item('Attiva graduatoria (unica in vigore)', 'activate')}
            {roster.active && item('Disattiva graduatoria', 'deactivate')}
            {scanned && item('Report', 'report')}
            {roster.has_file && item(roster.status === 'failed' ? 'Riprova importazione' : 'Rileggi CSV', 'scan')}
            {scanned && item('Esporta CSV', 'csv')}
        </ul>,
        document.body
    );
}

function RosterTile({ roster, busy, onMenu }) {
    const scanned = roster.status === 'scanned';
    const failed = roster.status === 'failed';
    const classes = ['tm-rosterfile'];
    if (roster.active) classes.push('tm-rosterfile--active');
    if (failed) classes.push('tm-rosterfile--failed');

    return (
        <button
            type="button"
            className={classes.join(' ')}
            onClick={(event) => onMenu(event, roster)}
            onContextMenu={(event) => { event.preventDefault(); onMenu(event, roster); }}
            title={failed && roster.error ? roster.error : roster.filename}
        >
            <span className="tm-rosterfile__art">
                <span className="tm-rosterfile__icon dashicons dashicons-media-spreadsheet" />
                {roster.active && <span className="tm-rosterfile__activelabel">ATTIVO</span>}
                {!busy && scanned && <span className="tm-rosterfile__check dashicons dashicons-yes-alt" title="CSV importato" />}
                {!busy && failed && <span className="tm-rosterfile__fail dashicons dashicons-warning" title="Importazione fallita" />}
                {busy && <span className="tm-rosterfile__busy"><Spinner /></span>}
            </span>
            <span className="tm-rosterfile__name">{roster.filename}</span>
            <span className="tm-rosterfile__meta">{roster.uploaded}{scanned && roster.doctors ? ` · ${roster.doctors} medici` : ''}</span>
            {busy && <span className="tm-rosterfile__state">Lettura del CSV.</span>}
            {!busy && roster.status === 'uploaded' && <span className="tm-rosterfile__state">Da importare</span>}
            {!busy && failed && <span className="tm-rosterfile__state tm-rosterfile__state--error">{roster.error || 'Importazione fallita'}</span>}
        </button>
    );
}

export default function CaRosterManager({ rosters, onClose, onReload, onDoctorsChanged }) {
    const [notice, setNotice] = useState(null);
    const [menu, setMenu] = useState(null);
    const [scanningIds, setScanningIds] = useState([]);
    const [uploading, setUploading] = useState(false);
    const [dragging, setDragging] = useState(false);
    const [confirming, setConfirming] = useState(null);
    const [activationBusy, setActivationBusy] = useState(false);
    const [activationReport, setActivationReport] = useState(null);
    const [report, setReport] = useState(null);
    const fileInputRef = useRef(null);
    const dragDepthRef = useRef(0);

    const scan = async (rosterId) => {
        setScanningIds((ids) => [...ids, rosterId]);
        try {
            const updated = await api(`doctors/ca-rosters/${rosterId}/scan`, { method: 'POST' });
            await onReload();
            if (updated.status === 'failed') {
                setNotice({ status: 'error', text: `Importazione fallita: ${updated.error}` });
            } else if (updated.active) {
                setNotice({ status: 'warning', text: "Hai riletto l'elenco in vigore: riattivalo per applicare eventuali differenze ai medici." });
            }
        } catch (err) {
            setNotice({ status: 'error', text: err.message });
            await onReload();
        } finally {
            setScanningIds((ids) => ids.filter((id) => id !== rosterId));
        }
    };

    const uploadFiles = async (fileList) => {
        const files = Array.from(fileList).filter((file) => /\.csv$/i.test(file.name));
        if (!files.length) {
            setNotice({ status: 'error', text: 'Servono file .csv in UTF-8.' });
            return;
        }
        setUploading(true);
        const toScan = [];
        for (const file of files) {
            try {
                const formData = new FormData();
                formData.append('file', file);
                const res = await api('doctors/ca-rosters/upload', { method: 'POST', body: formData });
                if (res.already_known && res.roster.status === 'scanned') {
                    setNotice({ status: 'info', text: `«${res.roster.filename}» era già in archivio, già importato.` });
                } else if (res.roster.status !== 'scanned') {
                    toScan.push(res.roster.id);
                }
            } catch (err) {
                setNotice({ status: 'error', text: `${file.name}: ${err.message}` });
            }
        }
        await onReload();
        setUploading(false);
        for (const rosterId of toScan) {
            await scan(rosterId);
        }
    };

    const openActivation = async (roster) => {
        setActivationBusy(true);
        try {
            const preview = await api(`doctors/ca-rosters/${roster.id}/preview`);
            setConfirming({ roster, ...preview });
        } catch (err) {
            setNotice({ status: 'error', text: err.message });
        } finally {
            setActivationBusy(false);
        }
    };

    const activate = async () => {
        setActivationBusy(true);
        try {
            const result = await api(`doctors/ca-rosters/${confirming.roster.id}/activate`, { method: 'POST' });
            setConfirming(null);
            setActivationReport(result);
            await onReload();
            if (onDoctorsChanged) onDoctorsChanged();
        } catch (err) {
            setNotice({ status: 'error', text: err.message });
        } finally {
            setActivationBusy(false);
        }
    };

    const applyContracts = async (roster) => {
        const horizon = roster.horizon ? `${roster.horizon.start} → ${roster.horizon.end}` : 'il periodo coperto dal file';
        if (!window.confirm(`Gli slot orari di «${roster.filename}» verranno sommati ai medici: nell'intervallo ${horizon} fanno fede quelli del file, fuori restano quelli attuali. Graduatoria ed elenco in vigore non cambiano. Continuare?`)) return;
        try {
            const result = await api(`doctors/ca-rosters/${roster.id}/apply-contracts`, { method: 'POST' });
            const parts = [`${result.updated} medici aggiornati`, `${result.created} creati`];
            if (result.trimmed) parts.push(`${result.trimmed} ridimensionati nell'intervallo del file`);
            setNotice({
                status: result.errors?.length ? 'warning' : 'success',
                text: `Contratti applicati da «${roster.filename}»: ${parts.join(', ')}. Graduatoria invariata.`
                    + (result.errors?.length ? ` Non applicati: ${result.errors.join('; ')}` : ''),
            });
            await onReload();
            if (onDoctorsChanged) onDoctorsChanged();
        } catch (err) {
            setNotice({ status: 'error', text: err.message });
        }
    };

    const deactivate = async (roster) => {
        const scope = roster.district ? ` per ${roster.district}` : '';
        if (!window.confirm(`Nessun elenco risulterà più in vigore${scope}: i dati dei medici restano come sono, ma nessun file sarà la fonte di verità. Continuare?`)) return;
        try {
            await api(`doctors/ca-rosters/${roster.id}/deactivate`, { method: 'POST' });
            await onReload();
        } catch (err) {
            setNotice({ status: 'error', text: err.message });
        }
    };

    const openReport = async (roster) => {
        try {
            setReport(await api(`doctors/ca-rosters/${roster.id}/report`));
        } catch (err) {
            setNotice({ status: 'error', text: err.message });
        }
    };

    const exportCsv = async (roster) => {
        try {
            const data = await api(`doctors/ca-rosters/${roster.id}/csv`);
            const blob = new Blob([data.csv], { type: 'text/csv;charset=utf-8' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = data.filename;
            link.click();
            URL.revokeObjectURL(link.href);
        } catch (err) {
            setNotice({ status: 'error', text: err.message });
        }
    };

    const onMenuAction = (action, roster) => {
        if (action === 'scan') scan(roster.id);
        if (action === 'activate') openActivation(roster);
        if (action === 'apply-contracts') applyContracts(roster);
        if (action === 'deactivate') deactivate(roster);
        if (action === 'report') openReport(roster);
        if (action === 'csv') exportCsv(roster);
    };

    const onDrop = (event) => {
        event.preventDefault();
        dragDepthRef.current = 0;
        setDragging(false);
        if (event.dataTransfer?.files?.length) uploadFiles(event.dataTransfer.files);
    };
    const onDragEnter = (event) => {
        event.preventDefault();
        dragDepthRef.current += 1;
        setDragging(true);
    };
    const onDragLeave = () => {
        dragDepthRef.current = Math.max(0, dragDepthRef.current - 1);
        if (dragDepthRef.current === 0) setDragging(false);
    };

    return (
        <Modal title="Archivio elenchi CA" onRequestClose={onClose} className="tm-rostermanager">
            {notice && <Notice status={notice.status} onRemove={() => setNotice(null)}>{notice.text}</Notice>}
            {activationReport && (
                <Notice status="success" onRemove={() => setActivationReport(null)}>
                    Versione attivata: creati {activationReport.created}, aggiornati {activationReport.updated}, disattivati {activationReport.deactivated?.length || 0}.
                    {activationReport.errors?.length ? ` Non importati: ${activationReport.errors.join('; ')}` : ''}
                </Notice>
            )}

            <p className="tm-rostermanager__hint">
                Trascina qui i CSV del distretto o usa «+»: la spunta indica un file importato.
                Tasto destro su un file per le due attivazioni: «Aggiungi gli slot orari» somma i contratti del file
                (cumulabile con tutti i file), «Attiva graduatoria» rende la sua graduatoria l'unica in vigore.
            </p>

            <details className="tm-rostermanager__hint">
                <summary>Formato CSV</summary>
                <p>UTF-8, separatore virgola, un solo distretto per file. Ogni riga rappresenta un medico CA temporaneo e un contratto; ripeti la riga per più contratti. Le date usano YYYY-MM-DD; lascia vuota la posizione per i medici a chiamata. In modalità demo usa solo dati fittizi.</p>
                <code>first_name,last_name,email,phone,qualification,aft,patients,role_start,ca_start,ca_end,ca_rank,ca_zone,ca_cot,district</code>
            </details>
            <div
                className={dragging ? 'tm-rostermanager__dropzone is-dragging' : 'tm-rostermanager__dropzone'}
                onDragEnter={onDragEnter}
                onDragOver={(event) => event.preventDefault()}
                onDragLeave={onDragLeave}
                onDrop={onDrop}
            >
                <div className="tm-rosterfiles">
                    {rosters.map((roster) => (
                        <RosterTile
                            key={roster.id}
                            roster={roster}
                            busy={scanningIds.includes(roster.id) || roster.scanning}
                            onMenu={(event, target) => setMenu({ x: event.clientX, y: event.clientY, roster: target })}
                        />
                    ))}
                    <button type="button" className="tm-rosterfile tm-rosterfile--add" onClick={() => fileInputRef.current?.click()} disabled={uploading}>
                        {uploading ? <Spinner /> : <span className="tm-rosterfile__plus" aria-hidden="true">+</span>}
                        <span className="tm-rosterfile__name">{uploading ? 'Caricamento…' : 'Aggiungi file'}</span>
                    </button>
                </div>
                {dragging && <div className="tm-rostermanager__drophint">Rilascia qui i file CSV</div>}
            </div>
            <input
                ref={fileInputRef}
                type="file"
                accept=".csv,text/csv"
                multiple
                hidden
                onChange={(event) => { uploadFiles(event.target.files); event.target.value = ''; }}
            />

            {menu && (
                <RosterContextMenu x={menu.x} y={menu.y} roster={menu.roster} onClose={() => setMenu(null)} onAction={onMenuAction} />
            )}

            {confirming && (
                <Modal title={`Attivare «${confirming.roster.filename}»?`} onRequestClose={() => setConfirming(null)} className="tm-rosterbar__modal">
                    <p>
                        Questo elenco diventa la fonte di verità per i medici CA{confirming.roster.district ? ` del distretto ${confirming.roster.district}` : ''} nel periodo che copre:
                        graduatoria e contratti di quel periodo vengono riallineati al file, comprese le eventuali modifiche fatte a mano.
                        I contratti dei periodi precedenti (altri trimestri) restano e si sommano ai nuovi.
                    </p>
                    <ul className="tm-rosterbar__counts">
                        <li><strong>{confirming.preview.new.length}</strong> nuovi medici</li>
                        <li><strong>{confirming.preview.updated.length}</strong> aggiornati</li>
                        <li><strong>{confirming.preview.unchanged.length}</strong> invariati</li>
                        <li><strong>{confirming.deactivating.length}</strong> disattivati</li>
                    </ul>
                    {confirming.deactivating.length > 0 && (
                        <div className="tm-rosterbar__deactivating">
                            <strong>Verranno disattivati (non sono nel file):</strong>
                            <ul>
                                {confirming.deactivating.map((doctor) => (
                                    <li key={doctor.user_id}>
                                        {doctor.name}
                                        {doctor.kept_periods > 0 && <span> — {keptPeriodsLabel(doctor.kept_periods)}</span>}
                                        {doctor.open_queues?.length > 0 && (
                                            <em> — attenzione: in coda su {doctor.open_queues.join(', ')}</em>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    <div className="tm-rosterbar__foot">
                        <Button variant="tertiary" onClick={() => setConfirming(null)}>Annulla</Button>
                        <Button variant="primary" disabled={activationBusy} onClick={activate}>{activationBusy ? 'Attivazione…' : 'Attiva versione'}</Button>
                    </div>
                </Modal>
            )}

            {report && (
                <Modal title={`Report — ${report.roster.filename}`} onRequestClose={() => setReport(null)} className="tm-rosterreport">
                    <div className="tm-rosterreport__summary">
                        {report.report.district && <span><strong>Distretto</strong> {report.report.district}</span>}
                        {report.report.horizon && <span><strong>Periodo coperto</strong> {report.report.horizon.start} → {report.report.horizon.end}</span>}
                        <span><strong>Righe lette</strong> {report.report.rows}</span>
                        <span><strong>Medici</strong> {report.report.doctors.length} ({report.report.ranked} in graduatoria, {report.report.on_call} a chiamata)</span>
                        <span><strong>Contratti</strong> {report.report.contracts}</span>
                        {report.roster.active && <span className="tm-rosterreport__active">Elenco in vigore</span>}
                    </div>
                    <table className="wp-list-table widefat striped tm-rosterreport__table">
                        <thead>
                            <tr><th>Pos.</th><th>Medico</th><th>Postazione</th><th>Contratti</th><th>Email</th><th>Telefono</th></tr>
                        </thead>
                        <tbody>
                            {report.report.doctors.map((doctor, index) => (
                                <tr key={index}>
                                    <td>{doctor.rank ?? 'a chiamata'}</td>
                                    <td>{doctor.name}{doctor.cot ? ' · COT' : ''}</td>
                                    <td>{doctor.zone}</td>
                                    <td>{doctor.periods.map((period) => `${period.start} → ${period.end}`).join(', ')}</td>
                                    <td>{doctor.email}</td>
                                    <td>{doctor.phone}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Modal>
            )}
        </Modal>
    );
}
