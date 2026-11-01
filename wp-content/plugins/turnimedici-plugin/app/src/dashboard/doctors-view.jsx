import { useState, useEffect } from '@wordpress/element';
import { getManagedDoctors, ensureDoctorLink } from './api';

const LinkIcon = () => (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" /><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" /></svg>
);
const CheckIcon = () => (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12" /></svg>
);

function copyToClipboard(text) {
    if (navigator.clipboard?.writeText) return navigator.clipboard.writeText(text);
    return new Promise((resolve, reject) => {
        const area = document.createElement('textarea');
        area.value = text;
        area.style.cssText = 'position:fixed;left:-9999px;top:0';
        document.body.appendChild(area);
        area.select();
        try {
            document.execCommand('copy');
            resolve();
        } catch (err) {
            reject(err);
        } finally {
            area.remove();
        }
    });
}

const normalizeText = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

function DoctorRow({ doctor, onLink }) {
    const [copied, setCopied] = useState(false);
    const [busy, setBusy] = useState(false);

    const copyLink = async (url) => {
        try {
            await copyToClipboard(url);
            setCopied(true);
            setTimeout(() => setCopied(false), 1800);
        } catch (err) {
            window.prompt('Copia il link manualmente:', url);
        }
    };

    const handleClick = async () => {
        if (doctor.magic_url) {
            copyLink(doctor.magic_url);
            return;
        }
        setBusy(true);
        try {
            const result = await ensureDoctorLink(doctor.id);
            onLink(doctor.id, result.magic_url);
            copyLink(result.magic_url);
        } catch (err) {
            window.alert(err.message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <li className="tmd-doctor">
            <div className="tmd-doctor__body">
                <strong className="tmd-doctor__name">{doctor.name}</strong>
                <span className="tmd-doctor__meta">
                    {[doctor.qualification, doctor.email, doctor.phone].filter(Boolean).join(' · ')}
                </span>
            </div>
            <button
                type="button"
                className={`tmd-btn tmd-doctor__copy${copied ? ' is-copied' : ''}`}
                disabled={busy}
                onClick={handleClick}
            >
                {copied ? <CheckIcon /> : <LinkIcon />}
                {copied ? 'Copiato' : (doctor.magic_url || doctor.has_magic_link ? 'Copia link' : 'Genera link')}
            </button>
        </li>
    );
}

export default function DoctorsView({ me }) {
    const [doctors, setDoctors] = useState(null);
    const [error, setError] = useState(null);
    const [query, setQuery] = useState('');
    const config = window.tmDashboard || {};

    useEffect(() => {
        getManagedDoctors().then(setDoctors).catch((err) => setError(err.message));
    }, []);

    if (error) return <div className="tmd-error">{error}</div>;
    if (!doctors) return <div className="tmd-loading">Caricamento…</div>;

    const setLink = (id, magic_url) => setDoctors(doctors.map((d) => (d.id === id ? { ...d, magic_url } : d)));

    const q = normalizeText(query.trim());
    const doctorSearch = (doctor) => normalizeText(`${doctor.name || ''} ${doctor.qualification || ''} ${doctor.email || ''} ${doctor.phone || ''}`);
    const rows = q
        ? doctors.filter((d) => doctorSearch(d).includes(q))
        : doctors;

    const scopeLabel = me?.is_admin ? 'Tutti i medici in turnazione' : `Medici della ${me?.coordinated_aft?.name || 'tua AFT'}`;

    return (
        <div className="tmd tmd-doctors">
            <div className="tmd-pagehead">
                <a className="tmv-back" href={`${config.homeUrl || '/'}#/dashboard`}>‹ Dashboard</a>
                <h1 className="tmd-pagetitle">Link di accesso medici</h1>
                <p className="tmd-pagesub">{scopeLabel} · il link è personale, invialo solo al diretto interessato</p>
            </div>

            <section className="tmd-panel">
                <div className="tmd-ranking__search">
                    <input
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Cerca per nome, telefono o email…"
                        aria-label="Cerca medico"
                    />
                    <span className="tmd-ranking__count">{rows.length} medici</span>
                </div>

                {rows.length === 0 ? (
                    <p className="tmd-empty">Nessun medico trovato.</p>
                ) : (
                    <ul className="tmd-doctorlist">
                        {rows.map((doctor) => <DoctorRow key={doctor.id} doctor={doctor} onLink={setLink} />)}
                    </ul>
                )}
            </section>
        </div>
    );
}
