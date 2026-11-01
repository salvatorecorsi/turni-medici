import { useState, useEffect } from '@wordpress/element';
import { Modal, Button, Spinner } from '@wordpress/components';
import { Icon, search } from '@wordpress/icons';
import { searchEstar, importEstar, estarStatus, uploadEstarDump } from './api';

const ESTAR_DUMP_URL = 'https://servizi.estar.toscana.it/adiba/ambulatori.php?a=0';

function Field({ label, value }) {
    if (value === undefined || value === null || value === '') return null;
    const [copied, setCopied] = useState(false);
    const copy = () => {
        navigator.clipboard?.writeText(String(value));
        setCopied(true);
        window.setTimeout(() => setCopied(false), 900);
    };
    return (
        <div className="tm-estar__field" onClick={copy} title="Copia">
            <span className="tm-estar__label">{label}</span>
            <span className="tm-estar__value">{copied ? 'Copiato!' : value}</span>
        </div>
    );
}

function EstarDoctor({ medico, onImport, importing }) {
    const isLocal = medico.local_id > 0;
    const [confirming, setConfirming] = useState(false);

    const changes = [];
    if (isLocal && medico.local) {
        if (Number(medico.local.patients) !== Number(medico.assistiti)) {
            changes.push({ label: 'Assistiti', from: medico.local.patients, to: medico.assistiti });
        }
        if ((medico.local.phone || '') !== (medico.estar_phone || '')) {
            changes.push({ label: 'Telefono', from: medico.local.phone || '—', to: medico.estar_phone || '—' });
        }
    }

    const onAction = () => {
        if (!isLocal) { onImport(medico); return; }
        if (!confirming) { setConfirming(true); return; }
        if (changes.length) onImport(medico);
        setConfirming(false);
    };

    const actionLabel = !isLocal
        ? 'Importa medico'
        : (confirming ? (changes.length ? 'Conferma aggiornamento' : 'Già allineato') : 'Aggiorna dati');

    return (
        <div className="tm-estar__doctor">
            <div className="tm-estar__doctor-head">
                <div className="tm-estar__name">
                    {medico.cognome} {medico.nome}
                    {isLocal && <span className="tm-estar__badge">in elenco</span>}
                </div>
                <Button
                    variant={isLocal ? 'secondary' : 'primary'}
                    isBusy={importing}
                    disabled={importing || (confirming && isLocal && changes.length === 0)}
                    onClick={onAction}
                >
                    {actionLabel}
                </Button>
            </div>

            {confirming && isLocal && (
                <div className="tm-estar__diff">
                    {changes.length === 0 ? (
                        <span className="tm-estar__diff-empty">Dati già allineati con ESTAR.</span>
                    ) : (
                        changes.map((change) => (
                            <div key={change.label} className="tm-estar__diff-row">
                                <span className="tm-estar__diff-label">{change.label}</span>
                                <span className="tm-estar__diff-from">{change.from}</span>
                                <span className="tm-estar__diff-arrow">→</span>
                                <span className="tm-estar__diff-to">{change.to}</span>
                            </div>
                        ))
                    )}
                    <Button variant="tertiary" onClick={() => setConfirming(false)}>Annulla</Button>
                </div>
            )}
            <div className="tm-estar__grid">
                <Field label="Tipologia" value={medico.tipologia} />
                <Field label="Ambito" value={medico.ambito} />
                <Field label="AFT" value={medico.aft} />
                <Field label="Cod. regionale" value={medico.codice_regionale} />
                <Field label="Assistiti" value={medico.assistiti} />
                <Field label="Scelte disp." value={medico.scelte_disponibili} />
                <Field label="ID ESTAR" value={medico.id} />
            </div>
            {(medico.ambulatori || []).map((ambulatorio, index) => (
                <div key={index} className="tm-estar__amb">
                    <div className="tm-estar__amb-head">Ambulatorio{ambulatorio.principale === 'Y' ? ' · principale' : ''}</div>
                    <Field label="Orario" value={ambulatorio.orario} />
                    <Field label="Comune" value={ambulatorio.comune} />
                    <Field label="Ubicazione" value={ambulatorio.ubicazione} />
                    <Field label="Telefono" value={ambulatorio.telefono} />
                    <Field label="Segreteria" value={ambulatorio.segreteria} />
                </div>
            ))}
        </div>
    );
}

export default function EstarPanel({ onClose, onImported }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [importingId, setImportingId] = useState(null);
    const [status, setStatus] = useState(null);
    const [syncing, setSyncing] = useState(false);

    useEffect(() => {
        if (!window.tmDoctors?.demoMode) estarStatus().then(setStatus).catch((error) => setError(error.message));
    }, []);

    const runSearch = async () => {
        const term = query.trim();
        if (term.length < 2) return;
        setLoading(true);
        setError(null);
        try {
            const data = await searchEstar(term);
            setResults(data.medici || []);
        } catch (err) {
            setError(err.message);
            setResults(null);
        } finally {
            setLoading(false);
        }
    };

    const onImport = async (medico, options = {}) => {
        setImportingId(medico.id);
        setError(null);
        try {
            const res = await importEstar(medico.id, options);
            setResults((current) => current.map((item) => (item.id === medico.id
                ? { ...item, local_id: res.doctor.id, local: { patients: res.doctor.patients, phone: res.doctor.phone } }
                : item)));
            onImported?.(res.doctor, res.action);
        } catch (err) {
            if (err.code === 'estar_aft_unmatched') {
                const aftTitle = err.data?.aft_title || err.data?.aft_name || medico.aft || 'senza nome';
                if (window.confirm(`L'AFT «${aftTitle}» non esiste ancora nel backend. Crearla adesso e importare il medico?`)) {
                    return await onImport(medico, { ...options, create_aft: true });
                }
                setError(`Import annullato: l'AFT «${aftTitle}» non è stata creata.`);
            } else {
                setError(err.message);
            }
        } finally {
            setImportingId(null);
        }
    };

    const refresh = async () => {
        if (window.tmDoctors?.demoMode) return;
        setError(null);
        setSyncing(true);
        try {
            const response = await fetch(ESTAR_DUMP_URL, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(`ESTAR ha risposto ${response.status}`);
            const dump = await response.json();
            const saved = await uploadEstarDump(dump);
            setStatus({ updated: saved.updated, count: saved.count });
        } catch (err) {
            setError(err instanceof TypeError
                ? "Impossibile leggere ESTAR dal browser (blocco CORS o rete). Apri il dump in una scheda per verificare."
                : `Aggiornamento fallito: ${err.message}`);
        } finally {
            setSyncing(false);
        }
    };

    const updatedLabel = status?.updated ? new Date(status.updated * 1000).toLocaleString('it-IT') : '—';

    if (window.tmDoctors?.demoMode) {
        return <Modal title="Ricerca medici ESTAR" onRequestClose={onClose}><p>La demo usa dati fittizi. L'accesso a ESTAR è disabilitato.</p></Modal>;
    }

    return (
        <Modal title="Ricerca medici ESTAR" onRequestClose={onClose} className="tm-estar-modal">
            <div className="tm-estar__search">
                <input
                    type="text"
                    className="tm-estar__input"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={(event) => event.key === 'Enter' && runSearch()}
                    placeholder="Cognome o nome del medico"
                    autoFocus
                />
                <Button variant="primary" icon={search} onClick={runSearch} disabled={loading}>Cerca</Button>
            </div>

            <div className="tm-estar__statusbar">
                <span>{status ? `${status.count} medici · aggiornato ${updatedLabel}` : ''}</span>
                <Button variant="link" isBusy={syncing} onClick={refresh}>Aggiorna dal server</Button>
            </div>

            <div className="tm-estar__body">
                {loading && <Spinner />}
                {error && <p className="tm-estar__error">{error}</p>}
                {!loading && results && results.length === 0 && <p className="tm-estar__empty">Nessun medico trovato.</p>}
                {!loading && results && results.map((medico) => (
                    <EstarDoctor key={medico.id} medico={medico} onImport={onImport} importing={importingId === medico.id} />
                ))}
            </div>
        </Modal>
    );
}
