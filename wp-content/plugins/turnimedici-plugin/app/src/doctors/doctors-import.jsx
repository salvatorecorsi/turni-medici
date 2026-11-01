import { useState } from '@wordpress/element';
import { FormFileUpload, Button, Modal } from '@wordpress/components';
import { importDoctors } from './api';

const CSV_FORMAT = `Prepara un file CSV UTF-8, separatore virgola, con queste colonne (intestazione inclusa):

first_name,last_name,email,phone,qualification,aft,patients,role_start,ca_start,ca_end

Regole per i valori:
- first_name: nome del medico
- last_name: cognome del medico
- email: indirizzo email; se non presente nel file, lascia la cella vuota
- phone: numero di telefono; se non presente, lascia la cella vuota
- qualification: esattamente uno tra "mmg", "ruap_volontario", "ruap_vincolato", "ruap_corsista", "ca_titolare", "ca_temporaneo"
- aft: nome esatto dell'AFT di appartenenza; obbligatorio per MMG, RUAP e ca_titolare, vuoto per ca_temporaneo
- patients: numero di assistiti (solo per i RUAP, altrimenti vuoto)
- role_start: data di inizio impiego come medico, formato YYYY-MM-DD
- ca_start, ca_end: un periodo di ingaggio in formato YYYY-MM-DD (solo per "ca_temporaneo", altrimenti vuoti)

Se lo stesso medico (stessa email) ha PIÙ periodi di ingaggio, mettilo su PIÙ righe, una per periodo: verranno uniti automaticamente. Non aggiungere colonne extra, non cambiare i nomi delle colonne, non inventare dati mancanti: se un valore non è deducibile dal file, lascia la cella vuota. Se la distinzione RUAP volontario/vincolato non è deducibile, usa "ruap_vincolato".`;

function splitCsvLine(line) {
    const cells = [];
    let current = '';
    let inQuotes = false;
    for (let i = 0; i < line.length; i++) {
        const char = line[i];
        if (char === '"') {
            if (inQuotes && line[i + 1] === '"') {
                current += '"';
                i++;
            } else {
                inQuotes = !inQuotes;
            }
        } else if (char === ',' && !inQuotes) {
            cells.push(current);
            current = '';
        } else {
            current += char;
        }
    }
    cells.push(current);
    return cells;
}

function parseCsv(text) {
    const lines = text.replace(/^\uFEFF/, "").split(/\r?\n/).filter((line) => line.trim());
    if (!lines.length) return [];
    const header = splitCsvLine(lines[0]).map((key) => key.trim().toLowerCase());
    return lines.slice(1).map((line) => {
        const cells = splitCsvLine(line);
        const row = {};
        header.forEach((key, index) => {
            row[key] = (cells[index] ?? '').trim();
        });
        return row;
    });
}

export default function ImportControls({ afts, onImported }) {
    const [showHelp, setShowHelp] = useState(false);
    const [importing, setImporting] = useState(false);

    const aftByName = {};
    afts.forEach((aft) => {
        aftByName[aft.name.toLowerCase()] = aft.id;
    });

    const handleFile = async (event) => {
        const file = event.target.files?.[0];
        if (!file) return;

        setImporting(true);
        const rows = parseCsv(await file.text()).map((row) => ({
            first_name: row.first_name,
            last_name: row.last_name,
            email: row.email,
            phone: row.phone,
            qualification: row.qualification,
            home_aft: row.aft ? aftByName[row.aft.toLowerCase()] || 0 : 0,
            patients: row.patients ? parseInt(row.patients, 10) || 0 : 0,
            role_start: row.role_start,
            ca_start: row.ca_start,
            ca_end: row.ca_end,
        }));

        try {
            const report = await importDoctors(rows);
            onImported();
            const summary = `Creati ${report.created}, aggiornati ${report.updated}.`;
            window.alert(report.errors.length ? `${summary}\nNon importati:\n${report.errors.join('\n')}` : summary);
        } catch (err) {
            window.alert(err.message);
        } finally {
            setImporting(false);
        }
    };

    const copyFormat = () => navigator.clipboard?.writeText(CSV_FORMAT);

    return (
        <>
            <Button variant="tertiary" onClick={() => setShowHelp(true)} aria-label="Aiuto formato CSV">
                ?
            </Button>
            <FormFileUpload accept=".csv" onChange={handleFile} variant="secondary" isBusy={importing} __next40pxDefaultSize>
                Importa CSV
            </FormFileUpload>

            {showHelp && (
                <Modal title="Importa medici da CSV" onRequestClose={() => setShowHelp(false)}>
<p>Esporta il foglio di calcolo come CSV UTF-8 con separatore virgola e usa il formato descritto qui sotto. Ogni riga rappresenta un medico e un eventuale contratto.</p>
                    <textarea
                        readOnly
                        rows={18}
                        value={CSV_FORMAT}
                        onFocus={(event) => event.target.select()}
                        style={{ width: '100%', fontFamily: 'monospace', fontSize: 12 }}
                    />
                    <div style={{ marginTop: 12 }}>
                        <Button variant="primary" onClick={copyFormat}>Copia formato</Button>
                    </div>
                </Modal>
            )}
        </>
    );
}
