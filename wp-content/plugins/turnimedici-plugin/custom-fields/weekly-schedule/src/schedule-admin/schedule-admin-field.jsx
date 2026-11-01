import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { Button, Modal, Notice, Spinner, TabPanel } from '@wordpress/components';
import {
    getScheduleAdmin,
    getScheduleSnapshot,
    runScheduleAdminAction,
    saveScheduleAdmin,
    validateScheduleDocument,
} from './api';
import { ActionLogPanel, DocumentToolbar, JsonStatePanel, SectionPanel, SnapshotsPanel } from './panels';
import QueuePanel from './queue-panel';

const DOCUMENT_SCHEMA = 'turnimedici.schedule-state';
const DOCUMENT_VERSION = 1;

const isStaleScheduleError = (error) => error?.code === 'tm_stale_schedule';

const formatDocument = (document) => JSON.stringify(document, null, 2);

function parseDocument(json, scheduleId) {
    let document;
    try {
        document = JSON.parse(json);
    } catch (error) {
        throw new Error(`JSON non valido: ${error.message}`);
    }
    if (!document || Array.isArray(document) || typeof document !== 'object') throw new Error('Il JSON deve contenere un oggetto.');
    if (document.schema !== DOCUMENT_SCHEMA) throw new Error('Schema JSON non riconosciuto.');
    if (Number(document.version) !== DOCUMENT_VERSION) throw new Error('Versione dello schema non supportata.');
    if (Number(document.schedule_id) !== Number(scheduleId)) throw new Error('Il JSON appartiene a un altro turno.');
    if (!document.meta || Array.isArray(document.meta) || typeof document.meta !== 'object') throw new Error('meta deve essere un oggetto.');
    if (!Array.isArray(document.assignments)) throw new Error('assignments deve essere un array.');
    if (!Array.isArray(document.shifts)) throw new Error('shifts deve essere un array.');
    return document;
}

const CALENDAR_META_KEYS = ['tm_schedule_month', 'tm_schedule_closed', 'tm_schedule_week'];
const OPERATIONAL_META_KEYS = [
    'tm_participants_confirmed_at',
    'tm_participants_confirmed_signature',
    'tm_ranking_snapshot',
    'tm_queue_notified',
    'tm_queue_notified_signature',
    'tm_completed_notified_signature',
];

const pickMetaKeys = (meta, keys) => Object.fromEntries(keys.map((key) => [key, meta?.[key] ?? '']));

const mergeMetaKeys = (document, fragment, keys, label) => {
    if (!fragment || Array.isArray(fragment) || typeof fragment !== 'object') throw new Error(`${label} deve essere un oggetto.`);
    const meta = { ...document.meta };
    keys.forEach((key) => {
        if (key in fragment) meta[key] = fragment[key];
    });
    return { ...document, meta };
};

const DOCUMENT_SECTIONS = {
    calendar: {
        title: 'Calendario',
        description: 'Mese, chiusura e griglia settimanale del turno: fasce orarie, giorni chiusi, override per data.',
        extract: (document) => pickMetaKeys(document.meta, CALENDAR_META_KEYS),
        merge: (document, fragment) => mergeMetaKeys(document, fragment, CALENDAR_META_KEYS, 'Il calendario'),
    },
    shifts: {
        title: 'Turni',
        description: 'Turni inseriti nel calendario: data, fascia oraria e medico.',
        extract: (document) => document.shifts,
        merge: (document, fragment) => {
            if (!Array.isArray(fragment)) throw new Error('I turni devono essere un array.');
            return { ...document, shifts: fragment };
        },
    },
    operational: {
        title: 'Stato operativo',
        description: 'Conferma partecipanti, firme, snapshot graduatoria e notifiche: stato gestito dal sistema, in sola lettura. Le firme derivano da medici e ore della coda.',
        extract: (document) => pickMetaKeys(document.meta, OPERATIONAL_META_KEYS),
    },
};

function downloadDocument(snapshotDocument, filename) {
    const blob = new Blob([formatDocument(snapshotDocument)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = window.document.createElement('a');
    link.href = url;
    link.download = filename;
    window.document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

export default function ScheduleAdminField() {
    const postId = useSelect((select) => select(editorStore).getCurrentPostId(), []);
    const [state, setState] = useState(null);
    const [json, setJson] = useState('');
    const [baseline, setBaseline] = useState('');
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState('');
    const [error, setError] = useState('');
    const [notice, setNotice] = useState(null);
    const [confirmation, setConfirmation] = useState(null);
    const [validation, setValidation] = useState({ status: 'info', message: 'In attesa del database' });
    const [conflict, setConflict] = useState(null);
    const [sectionDrafts, setSectionDrafts] = useState({});
    const [sectionErrors, setSectionErrors] = useState({});
    const fileInput = useRef(null);

    const resetSections = useCallback(() => {
        setSectionDrafts({});
        setSectionErrors({});
    }, []);

    const applyState = useCallback((nextState) => {
        const nextJson = formatDocument(nextState.document);
        setState(nextState);
        setJson(nextJson);
        setBaseline(nextJson);
        setDirty(false);
        setConflict(null);
        resetSections();
        setValidation(nextState.document_validation?.valid
            ? { status: 'success', message: nextState.document_validation.message || 'JSON valido' }
            : { status: 'error', message: nextState.document_validation?.message || 'Stato scaricato, non ancora validato dal server' });
    }, [resetSections]);

    const fetchDatabase = useCallback(async () => {
        if (!postId) return;
        setConfirmation(null);
        setBusy('database');
        setError('');
        setNotice(null);
        try {
            applyState(await getScheduleAdmin(postId));
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setBusy('');
        }
    }, [postId, applyState]);

    useEffect(() => {
        fetchDatabase();
    }, [fetchDatabase]);

    const updateJson = (nextJson) => {
        setJson(nextJson);
        setDirty(nextJson !== baseline);
        setNotice(null);
        try {
            parseDocument(nextJson, postId);
            setValidation({ status: 'info', message: 'Struttura JSON leggibile, non ancora validata dal server' });
        } catch (parseError) {
            setValidation({ status: 'error', message: parseError.message });
        }
    };

    const updateJsonRaw = (nextJson) => {
        resetSections();
        updateJson(nextJson);
    };

    const currentDocument = () => {
        try {
            return parseDocument(json, postId);
        } catch (parseError) {
            setValidation({ status: 'error', message: parseError.message });
            return null;
        }
    };

    let draftDocument = null;
    try {
        draftDocument = parseDocument(json, postId);
    } catch (parseError) {
        draftDocument = null;
    }

    const sectionValue = (name) => sectionDrafts[name]
        ?? (draftDocument ? formatDocument(DOCUMENT_SECTIONS[name].extract(draftDocument)) : '');

    const changeSection = (name, text) => {
        setSectionDrafts((drafts) => ({ ...drafts, [name]: text }));
        let fragment;
        try {
            fragment = JSON.parse(text);
        } catch (fragmentError) {
            setSectionErrors((errors) => ({ ...errors, [name]: `JSON non valido: ${fragmentError.message}` }));
            return;
        }
        const document = currentDocument();
        if (!document) return;
        try {
            updateJson(formatDocument(DOCUMENT_SECTIONS[name].merge(document, fragment)));
            setSectionErrors((errors) => ({ ...errors, [name]: '' }));
        } catch (mergeError) {
            setSectionErrors((errors) => ({ ...errors, [name]: mergeError.message }));
        }
    };

    const applyAssignment = (assignmentPayload, doctorName) => {
        setConfirmation({
            message: `Applicare subito al database le modifiche per ${doctorName}? Verrà creato uno snapshot di sicurezza e le firme saranno riallineate senza inviare email.`,
            action: async () => {
                setConfirmation(null);
                setBusy('assignment');
                setError('');
                setNotice(null);
                try {
                    const response = await runScheduleAdminAction(postId, {
                        operation: 'update_assignment',
                        state_hash: state.state_hash,
                        assignment: assignmentPayload,
                    });
                    applyState(response.state);
                    setNotice({ status: response.notice?.type === 'success' ? 'success' : 'warning', message: response.notice?.message || 'Operazione completata.' });
                } catch (requestError) {
                    if (isStaleScheduleError(requestError)) {
                        await fetchDatabase();
                        setNotice({ status: 'warning', message: 'Il turno era cambiato nel database: stato ricaricato, ricontrolla la coda e riprova.' });
                    } else {
                        setError(requestError.message);
                    }
                } finally {
                    setBusy('');
                }
            },
            destructive: true,
        });
    };

    const applyReorder = async (order) => {
        setBusy('reorder');
        setError('');
        setNotice(null);
        try {
            const response = await runScheduleAdminAction(postId, {
                operation: 'reorder_assignments',
                state_hash: state.state_hash,
                order,
            });
            applyState(response.state);
            setNotice({ status: response.notice?.type === 'success' ? 'success' : 'warning', message: response.notice?.message || 'Operazione completata.' });
        } catch (requestError) {
            if (isStaleScheduleError(requestError)) {
                await fetchDatabase();
                setNotice({ status: 'warning', message: 'Il turno era cambiato nel database: stato ricaricato, ricontrolla la coda e riprova.' });
            } else {
                setError(requestError.message);
            }
        } finally {
            setBusy('');
        }
    };

    const requestOperationalReset = () => {
        const document = currentDocument();
        if (!document) return;
        setConfirmation({
            message: 'Azzerare conferma partecipanti, firme, snapshot graduatoria e notifiche nella bozza? Dopo «Carica nel DB» la coda tornerà da confermare.',
            action: () => {
                const meta = { ...document.meta };
                OPERATIONAL_META_KEYS.forEach((key) => { meta[key] = ''; });
                updateJson(formatDocument({ ...document, meta }));
                resetSections();
                setConfirmation(null);
                setNotice({ status: 'info', message: 'Stato operativo azzerato nella bozza. Usa «Carica nel DB» per applicarlo.' });
            },
            destructive: true,
        });
    };

    const validate = async () => {
        const document = currentDocument();
        if (!document) return false;
        setBusy('validate');
        setError('');
        try {
            const result = await validateScheduleDocument(postId, document);
            setValidation({ status: 'success', message: result.message || 'JSON valido' });
            return true;
        } catch (requestError) {
            setValidation({ status: 'error', message: requestError.message });
            return false;
        } finally {
            setBusy('');
        }
    };

    const refreshConflict = async (message = 'Il database è cambiato mentre lavoravi. Esegui il merge manuale prima di caricare.') => {
        setBusy('conflict');
        setError('');
        try {
            const latestState = await getScheduleAdmin(postId);
            const latestJson = formatDocument(latestState.document);
            setState(latestState);
            setBaseline(latestJson);
            setDirty(json !== latestJson);
            setConflict({ state: latestState, json: latestJson });
            setValidation({ status: 'info', message: 'Confronta la bozza con il DB, modifica la bozza e validala prima del caricamento' });
            setNotice({ status: 'warning', message });
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setBusy('');
        }
    };

    const uploadDatabase = async (expectedHash, merged = false) => {
        const document = currentDocument();
        if (!document) return;
        setConfirmation(null);
        setBusy('upload');
        setError('');
        setNotice(null);
        try {
            const nextState = await saveScheduleAdmin(postId, {
                state_hash: expectedHash,
                document,
            });
            applyState(nextState);
            setNotice({ status: 'success', message: merged
                ? 'Merge caricato nel database. Lo stato precedente è stato salvato negli snapshot.'
                : 'JSON caricato nel database. Lo stato precedente è stato salvato negli snapshot.' });
        } catch (requestError) {
            if (isStaleScheduleError(requestError)) await refreshConflict();
            else setError(requestError.message);
        } finally {
            setBusy('');
        }
    };

    const requestDatabaseLoad = () => {
        if (!dirty) fetchDatabase();
        else setConfirmation({
            message: 'Scartare le modifiche locali e scaricare nuovamente lo stato dal database?',
            action: fetchDatabase,
            destructive: true,
        });
    };

    const requestDatabaseUpload = () => {
        if (!currentDocument()) return;
        setConfirmation({
            message: 'Caricare questo JSON nel database e sostituire lo stato live del turno?',
            action: () => uploadDatabase(state.state_hash),
            destructive: true,
        });
    };

    const requestMergedUpload = () => {
        if (!conflict || !currentDocument()) return;
        setConfirmation({
            message: 'Caricare il merge manuale usando come base la versione DB mostrata nel confronto?',
            action: () => uploadDatabase(conflict.state.state_hash, true),
            destructive: true,
        });
    };

    const requestDatabaseVersion = () => {
        if (!conflict) return;
        setConfirmation({
            message: 'Scartare definitivamente la bozza locale e usare la versione attuale del database?',
            action: () => {
                applyState(conflict.state);
                setConfirmation(null);
                setNotice({ status: 'info', message: 'Bozza locale scartata. Il JSON mostra lo stato attuale del database.' });
            },
            destructive: true,
        });
    };

    const exportJson = () => {
        const document = currentDocument();
        if (!document) return;
        const month = String(document.meta?.tm_schedule_month || 'turno');
        downloadDocument(document, `turnimedici-turno-${postId}-${month}.json`);
    };

    const importJson = async (event) => {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file) return;
        try {
            const document = parseDocument(await file.text(), postId);
            const nextJson = formatDocument(document);
            setJson(nextJson);
            setDirty(nextJson !== baseline);
            resetSections();
            setValidation({ status: 'info', message: 'File importato, non ancora validato dal server' });
            setNotice({ status: 'info', message: 'File caricato nell’editor JSON.' });
        } catch (parseError) {
            setValidation({ status: 'error', message: parseError.message });
        }
    };

    const createSnapshot = async () => {
        setBusy('snapshot');
        setError('');
        try {
            const response = await runScheduleAdminAction(postId, {
                operation: 'create_snapshot',
                state_hash: state.state_hash,
            });
            setState(response.state);
            setNotice({ status: 'success', message: 'Snapshot creato.' });
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setBusy('');
        }
    };

    const getSnapshotDocument = async (reference) => {
        setBusy('snapshot');
        setError('');
        try {
            return await getScheduleSnapshot(postId, reference);
        } catch (requestError) {
            setError(requestError.message);
            return null;
        } finally {
            setBusy('');
        }
    };

    const openSnapshot = async (reference) => {
        const document = await getSnapshotDocument(reference);
        if (!document) return;
        const nextJson = formatDocument(document);
        setJson(nextJson);
        setDirty(nextJson !== baseline);
        resetSections();
        setValidation({ status: 'info', message: 'Snapshot aperto, non ancora validato dal server' });
        setNotice({ status: 'info', message: 'Snapshot caricato nell’editor JSON.' });
    };

    const downloadSnapshot = async (reference) => {
        const document = await getSnapshotDocument(reference);
        if (!document) return;
        downloadDocument(document, `turnimedici-turno-${postId}-snapshot-${reference.slice(0, 8)}.json`);
    };

    if (!postId || (!state && busy)) return <div className="tm-schedule-admin__loading"><Spinner /></div>;
    if (!state) return <Notice status="error" isDismissible={false}>{error || 'Impossibile caricare il turno.'}</Notice>;

    const tabs = [
        { name: 'calendar', title: 'Calendario' },
        { name: 'queue', title: 'Coda' },
        { name: 'shifts', title: 'Turni' },
        { name: 'operational', title: 'Stato operativo' },
        { name: 'json', title: 'JSON completo' },
        { name: 'snapshots', title: `Snapshot (${state.snapshots.length})` },
        { name: 'logs', title: `Log (${state.logs.length})` },
    ];

    const sectionsInvalid = Object.values(sectionErrors).some(Boolean);
    const toolbarValidation = sectionsInvalid
        ? { status: 'error', message: 'Correggi le sezioni con errori JSON prima di caricare.' }
        : validation;

    return (
        <div className="tm-schedule-admin">
            <header className="tm-schedule-admin__header">
                <strong>Turno #{state.id} · {state.title}</strong>
                <div className="tm-schedule-admin__header-actions">
                    {dirty && <span className="tm-schedule-admin__unsaved">Modifiche locali</span>}
                    {busy && <Spinner />}
                    {state.app_url && <Button variant="secondary" href={state.app_url}>Apri nell’app</Button>}
                </div>
            </header>
            {notice && <Notice status={notice.status} onRemove={() => setNotice(null)}>{notice.message}</Notice>}
            {error && <Notice status="error" onRemove={() => setError('')}>{error}</Notice>}
            {(state.migration_error || state.diagnostics.length > 0) && (
                <div className="tm-schedule-admin__diagnostics">
                    {state.migration_error && <div className="tm-schedule-admin__diagnostic tm-schedule-admin__diagnostic--error">{state.migration_error}</div>}
                    {state.diagnostics.map((item, index) => <div key={`${item.level}-${index}`} className={`tm-schedule-admin__diagnostic tm-schedule-admin__diagnostic--${item.level}`}>{item.message}</div>)}
                </div>
            )}
            <DocumentToolbar busy={Boolean(busy)} dirty={dirty} validation={toolbarValidation} conflict={conflict} onLoadDatabase={requestDatabaseLoad} onUploadDatabase={requestDatabaseUpload} onValidate={validate} onExport={exportJson} onImport={() => fileInput.current?.click()} />
            <TabPanel className="tm-schedule-admin__tabs" tabs={tabs}>
                {(tab) => {
                    if (tab.name === 'snapshots') return <SnapshotsPanel snapshots={state.snapshots} busy={Boolean(busy)} onCreate={createSnapshot} onOpen={openSnapshot} onDownload={downloadSnapshot} />;
                    if (tab.name === 'logs') return <ActionLogPanel logs={state.logs} />;
                    if (tab.name === 'json') return <JsonStatePanel json={json} dirty={dirty} validation={validation} busy={Boolean(busy)} conflict={conflict} onChange={updateJsonRaw} onRefreshConflict={() => refreshConflict('Versione DB aggiornata. La bozza locale non è stata modificata.')} onUseDatabaseVersion={requestDatabaseVersion} onUploadMergedVersion={requestMergedUpload} />;
                    if (tab.name === 'queue') return (
                        <QueuePanel
                            assignments={state.assignments}
                            qualifications={state.qualifications || []}
                            aft={state.aft}
                            currentDoctorId={state.current_doctor_id}
                            busy={Boolean(busy)}
                            locked={dirty || Boolean(conflict)}
                            lockedMessage={conflict
                                ? 'C’è un conflitto col database: risolvilo dal tab «JSON completo» prima di modificare la coda.'
                                : 'Hai modifiche locali non caricate: questo pannello scrive direttamente nel database. Carica o scarta la bozza prima di applicare.'}
                            onApply={applyAssignment}
                            onReorder={applyReorder}
                        />
                    );
                    const section = DOCUMENT_SECTIONS[tab.name];
                    const readOnly = !section.merge;
                    return (
                        <SectionPanel
                            title={section.title}
                            description={section.description}
                            value={sectionValue(tab.name)}
                            error={sectionErrors[tab.name]}
                            busy={Boolean(busy)}
                            readOnly={readOnly}
                            compact={tab.name === 'operational'}
                            unavailable={!draftDocument}
                            actions={tab.name === 'operational'
                                ? <Button variant="secondary" isDestructive disabled={Boolean(busy) || !draftDocument} onClick={requestOperationalReset}>Azzera conferma e notifiche</Button>
                                : null}
                            onChange={readOnly ? undefined : (text) => changeSection(tab.name, text)}
                        />
                    );
                }}
            </TabPanel>
            <input ref={fileInput} type="file" accept="application/json,.json" hidden onChange={importJson} />
            {confirmation && (
                <Modal title="Conferma operazione" onRequestClose={() => setConfirmation(null)}>
                    <p>{confirmation.message}</p>
                    <div className="tm-schedule-admin__modal-actions">
                        <Button variant="tertiary" onClick={() => setConfirmation(null)}>Annulla</Button>
                        <Button variant="primary" isDestructive={confirmation.destructive} onClick={confirmation.action}>Conferma</Button>
                    </div>
                </Modal>
            )}
        </div>
    );
}
