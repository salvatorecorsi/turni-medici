import { Button } from '@wordpress/components';
import { backup, check, cloudDownload, cloudUpload, download, pencil, upload } from '@wordpress/icons';

export function DocumentToolbar({ busy, dirty, validation, conflict, onLoadDatabase, onUploadDatabase, onValidate, onExport, onImport }) {
    return (
        <div className="tm-schedule-admin__document-bar">
            <div className="tm-schedule-admin__toolbar">
                <div className="tm-schedule-admin__toolbar-group">
                    <strong>Database</strong>
                    <Button icon={cloudDownload} variant="secondary" disabled={busy} onClick={onLoadDatabase}>Scarica dal DB</Button>
                    <Button icon={cloudUpload} variant="primary" disabled={busy || Boolean(conflict) || !dirty || validation.status === 'error'} onClick={onUploadDatabase}>Carica nel DB</Button>
                </div>
                <div className="tm-schedule-admin__toolbar-group">
                    <strong>File</strong>
                    <Button icon={download} variant="secondary" disabled={busy || validation.status === 'error'} onClick={onExport}>Esporta JSON</Button>
                    <Button icon={upload} variant="secondary" disabled={busy} onClick={onImport}>Importa JSON</Button>
                    <Button icon={check} variant="tertiary" disabled={busy || validation.status === 'error'} onClick={onValidate}>Valida</Button>
                </div>
            </div>
            <div className={`tm-schedule-admin__json-status tm-schedule-admin__json-status--${validation.status}`}>
                <span>{conflict ? 'Merge manuale richiesto' : dirty ? 'Modifiche locali' : 'Sincronizzato col database'}</span>
                <span>{validation.message}</span>
            </div>
        </div>
    );
}

export function SectionPanel({ title, description, value, error, busy, readOnly, unavailable, compact, actions, onChange }) {
    return (
        <div className="tm-schedule-admin__json-panel">
            <div className="tm-schedule-admin__section-bar">
                <p className="tm-schedule-admin__section-desc">{description}</p>
                {actions}
            </div>
            {unavailable ? (
                <div className="tm-schedule-admin__conflict-notice">
                    <strong>JSON completo non valido</strong>
                    <span>Correggi la struttura nel tab «JSON completo» prima di lavorare su questa sezione.</span>
                </div>
            ) : (
                <textarea
                    className={`tm-schedule-admin__json${readOnly ? ' tm-schedule-admin__json--readonly' : ''}${compact ? ' tm-schedule-admin__json--compact' : ''}`}
                    value={value}
                    onChange={readOnly ? undefined : (event) => onChange(event.target.value)}
                    readOnly={readOnly || busy}
                    spellCheck="false"
                    aria-label={title}
                />
            )}
            {error && (
                <div className="tm-schedule-admin__json-status tm-schedule-admin__json-status--error">
                    <span>Sezione non applicata alla bozza</span>
                    <span>{error}</span>
                </div>
            )}
        </div>
    );
}

export function JsonStatePanel({
    json,
    dirty,
    validation,
    busy,
    conflict,
    onChange,
    onRefreshConflict,
    onUseDatabaseVersion,
    onUploadMergedVersion,
}) {
    return (
        <div className="tm-schedule-admin__json-panel">
            {conflict && (
                <div className="tm-schedule-admin__conflict-notice">
                    <strong>Il turno è cambiato nel database</strong>
                    <span>La bozza locale è intatta. Integra manualmente le modifiche della versione DB, poi carica il risultato.</span>
                </div>
            )}
            <div className={`tm-schedule-admin__json-workspace${conflict ? ' tm-schedule-admin__json-workspace--conflict' : ''}`}>
                <label className="tm-schedule-admin__json-column">
                    <span>{conflict ? 'La tua bozza' : 'Stato del turno'}</span>
                    <textarea
                        className="tm-schedule-admin__json"
                        value={json}
                        onChange={(event) => onChange(event.target.value)}
                        readOnly={busy}
                        spellCheck="false"
                        aria-label="Stato JSON del turno"
                    />
                </label>
                {conflict && (
                    <label className="tm-schedule-admin__json-column">
                        <span>Versione attuale DB · sola lettura</span>
                        <textarea
                            className="tm-schedule-admin__json tm-schedule-admin__json--readonly"
                            value={conflict.json}
                            readOnly
                            spellCheck="false"
                            aria-label="Versione JSON attuale nel database"
                        />
                    </label>
                )}
            </div>
            {conflict && (
                <div className="tm-schedule-admin__conflict-actions">
                    <Button icon={cloudDownload} variant="tertiary" disabled={busy} onClick={onRefreshConflict}>Aggiorna versione DB</Button>
                    <Button variant="secondary" disabled={busy} onClick={onUseDatabaseVersion}>Scarta bozza e usa DB</Button>
                    <Button icon={cloudUpload} variant="primary" disabled={busy || !dirty || validation.status === 'error'} onClick={onUploadMergedVersion}>Carica merge nel DB</Button>
                </div>
            )}
        </div>
    );
}

export function SnapshotsPanel({ snapshots, busy, onCreate, onOpen, onDownload }) {
    return (
        <section className="tm-schedule-admin__section">
            <div className="tm-schedule-admin__section-bar">
                <h3>Revisioni JSON</h3>
                <Button icon={backup} variant="secondary" disabled={busy} onClick={onCreate}>Crea snapshot</Button>
            </div>
            <div className="tm-schedule-admin__table-wrap">
                <table className="tm-schedule-admin__table">
                    <thead><tr><th>Data</th><th>Azione</th><th>Utente</th><th></th></tr></thead>
                    <tbody>
                        {snapshots.length === 0 && <tr><td colSpan="4" className="tm-schedule-admin__empty">Nessuno snapshot.</td></tr>}
                        {snapshots.map((snapshot) => (
                            <tr key={snapshot.reference}>
                                <td><code>{snapshot.created_at}</code></td>
                                <td>{snapshot.action}</td>
                                <td>{snapshot.actor}</td>
                                <td className="tm-schedule-admin__row-actions">
                                    <Button icon={pencil} variant="tertiary" disabled={busy} onClick={() => onOpen(snapshot.reference)}>Apri JSON</Button>
                                    <Button icon={download} variant="tertiary" disabled={busy} onClick={() => onDownload(snapshot.reference)}>Scarica</Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

export function ActionLogPanel({ logs }) {
    return (
        <section className="tm-schedule-admin__section">
            <div className="tm-schedule-admin__section-bar"><h3>Azioni registrate</h3></div>
            <div className="tm-schedule-admin__table-wrap">
                <table className="tm-schedule-admin__table tm-schedule-admin__table--logs">
                    <thead><tr><th>Data</th><th>Azione</th><th>Medico</th><th>Utente</th><th>Dettaglio</th></tr></thead>
                    <tbody>
                        {logs.length === 0 && <tr><td colSpan="5" className="tm-schedule-admin__empty">Nessuna azione.</td></tr>}
                        {logs.map((log) => (
                            <tr key={log.id}>
                                <td><code>{log.created_at}</code></td>
                                <td><code>{log.action}</code></td>
                                <td>{log.doctor || '—'}</td>
                                <td>{log.actor || 'Sistema'}</td>
                                <td>{log.detail || '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
