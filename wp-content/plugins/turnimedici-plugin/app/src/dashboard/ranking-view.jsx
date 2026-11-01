import { useState, useEffect, useRef } from '@wordpress/element';
import { getRankingList, ensureDoctorLink, getRankingBookmark, saveRankingBookmark } from './api';

const isTouchDevice = typeof window !== 'undefined' && window.matchMedia?.('(hover: none)')?.matches;

const PrintIcon = () => (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="6 9 6 2 18 2 18 9" /><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" /><rect x="6" y="14" width="12" height="8" /></svg>
);
const CopyIcon = () => (
    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="8" y="8" width="12" height="12" rx="2" /><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" /></svg>
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

function valueOrDash(value) {
    return value === null || value === undefined || value === '' ? '—' : value;
}

const normalizeText = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

const initialScheduleId = () => {
    const search = new URLSearchParams(window.location.search);
    const fromSearch = Number(search.get('schedule')) || 0;
    if (fromSearch) return fromSearch;

    const hashQuery = window.location.hash.includes('?') ? window.location.hash.split('?')[1] : '';
    const hash = new URLSearchParams(hashQuery);
    return Number(hash.get('schedule')) || 0;
};

function DoctorModal({ row, sharing, copied, onShare, onClose }) {
    const fields = [
        ['Posizione', row.position],
        ['Categoria', row.category],
        ['Qualifica', row.qualification],
        ['Loc.', row.loc || '—'],
        ['N. graduatoria aziendale', row.ca_rank !== null ? row.ca_rank : '—'],
        ['Ricollocamento COT', row.ca_cot ? 'Sì' : 'No'],
        ...(row.email !== undefined || row.phone !== undefined
            ? [['Email', row.email || '—'], ['Telefono', row.phone || '—']]
            : []),
    ];

    return (
        <div className="tmp-overlay" onClick={onClose}>
            <div className="tmp-modal tmp-modal--rank" onClick={(e) => e.stopPropagation()}>
                <div className="tmp-modal__head">
                    <h2>{row.name}</h2>
                    <button type="button" className="tmp-modal__close" onClick={onClose} aria-label="Chiudi">×</button>
                </div>
                <div className="tmp-modal__body tmp-modal__body--rank">
                    <div className="tmd-rankmodal__fields">
                        {fields.map(([label, value]) => (
                            <div key={label} className="tmd-rankmodal__field">
                                <span className="tmd-rankmodal__label">{label}</span>
                                <strong>{valueOrDash(value)}</strong>
                            </div>
                        ))}
                    </div>
                </div>
                <div className="tmp-modal__foot">
                    {row.can_share_link && (
                        <div className="tmd-rankmodal__copy">
                            <div className="tmd-rankmodal__actions">
                                <button type="button" className="tmp-btn tmp-btn--primary tmd-rankmodal__share" disabled={!!sharing} onClick={() => onShare(row, 'message')}>
                                    <CopyIcon /> {sharing?.kind === 'message' ? 'Preparazione…' : 'Copia messaggio'}
                                </button>
                                <button type="button" className="tmp-btn tmp-btn--ghost tmd-rankmodal__share" disabled={!!sharing} onClick={() => onShare(row, 'link')}>
                                    <CopyIcon /> {sharing?.kind === 'link' ? 'Preparazione…' : 'Copia link'}
                                </button>
                            </div>
                            {copied && <span className="tmd-rankmodal__copied">{copied.kind === 'message' ? 'Messaggio copiato' : 'Link copiato'}</span>}
                        </div>
                    )}
                    <button type="button" className="tmp-btn tmp-btn--ghost" onClick={onClose}>Chiudi</button>
                </div>
            </div>
        </div>
    );
}

export default function RankingView({ me, facilityId = 0 }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState(null);
    const [links, setLinks] = useState({});
    const [sharing, setSharing] = useState(null);
    const [copied, setCopied] = useState(null);
    const [bookmarkId, setBookmarkId] = useState(0);
    const lastTapRef = useRef({ id: 0, time: 0 });
    const pendingOpenRef = useRef(null);
    const lastLocalChangeRef = useRef(0);
    const config = window.tmDashboard || {};
    const scheduleId = initialScheduleId();

    const aftId = me?.coordinated_aft?.id || me?.aft?.id || 0;

    const bookmarkKey = `tm-rank-bookmark:${aftId}:${facilityId}:${scheduleId}:${data?.month || ''}`;

    useEffect(() => {
        getRankingList({ aftId, facilityId, scheduleId }).then(setData).catch((err) => setError(err.message));
    }, [aftId, facilityId, scheduleId]);

    useEffect(() => {
        if (!data) return;
        let cancelled = false;
        getRankingBookmark(bookmarkKey)
            .then((res) => {
                if (cancelled) return;
                let stored = Number(res.doctor_id) || 0;
                if (!stored) {
                    let local = 0;
                    try { local = Number(window.localStorage.getItem(bookmarkKey)) || 0; } catch {}
                    if (local) {
                        stored = local;
                        saveRankingBookmark(bookmarkKey, local).catch(() => {});
                        try { window.localStorage.removeItem(bookmarkKey); } catch {}
                    }
                }
                setBookmarkId(stored);
            })
            .catch(() => {
                if (!cancelled) setBookmarkId(0);
            });
        return () => { cancelled = true; };
    }, [data, bookmarkKey]);

    useEffect(() => {
        if (!data) return;
        const interval = window.setInterval(() => {
            if (document.visibilityState !== 'visible') return;
            const startedAt = Date.now();
            getRankingBookmark(bookmarkKey)
                .then((res) => {
                    if (startedAt < lastLocalChangeRef.current) return;
                    setBookmarkId(Number(res.doctor_id) || 0);
                })
                .catch(() => {});
        }, 3000);
        return () => window.clearInterval(interval);
    }, [data, bookmarkKey]);

    if (error) return <div className="tmd-error">{error}</div>;
    if (!data) return <div className="tmd-loading">Caricamento…</div>;

    const list = data.list || [];
    const q = normalizeText(query.trim());
    const rowSearch = (row) => normalizeText(`${row.name || ''} ${row.category || ''} ${row.qualification || ''} ${row.loc || ''} ${row.email || ''} ${row.phone || ''}`);
    const rows = q
        ? list.filter((row) => rowSearch(row).includes(q))
        : list;

    const openRow = (row) => setSelected(row);

    const toggleBookmark = (rowId) => {
        const next = bookmarkId === rowId ? 0 : rowId;
        lastLocalChangeRef.current = Date.now();
        setBookmarkId(next);
        saveRankingBookmark(bookmarkKey, next).catch(() => {});
    };

    const handleRowClick = (row) => {
        if (!isTouchDevice) {
            openRow(row);
            return;
        }
        const now = Date.now();
        const lastTap = lastTapRef.current;
        if (pendingOpenRef.current) {
            clearTimeout(pendingOpenRef.current);
            pendingOpenRef.current = null;
        }
        if (lastTap.id === row.id && now - lastTap.time < 350) {
            lastTapRef.current = { id: 0, time: 0 };
            toggleBookmark(row.id);
            return;
        }
        lastTapRef.current = { id: row.id, time: now };
        pendingOpenRef.current = setTimeout(() => {
            pendingOpenRef.current = null;
            openRow(row);
        }, 350);
    };
    const share = async (row, kind) => {
        setSharing({ id: row.id, kind });
        try {
            const payload = links[row.id] || (await ensureDoctorLink(row.id, { facilityId, scheduleId }));
            setLinks((prev) => ({ ...prev, [row.id]: payload }));
            await copyToClipboard(kind === 'message' ? payload.message : payload.magic_url);
            setCopied({ id: row.id, kind });
            setTimeout(() => setCopied(null), 1800);
        } catch (err) {
            window.alert(err.message);
        } finally {
            setSharing(null);
        }
    };

    return (
        <div className="tmd tmd-ranking">
            <div className="tmd-pagehead tmd-ranking__head">
                <div>
                    <a className="tmv-back" href={`${config.homeUrl || '/'}#/dashboard`}>‹ Dashboard</a>
                    <h1 className="tmd-pagetitle">Graduatoria di chiamata · mese di {data.month_label}</h1>
                    <p className="tmd-pagesub">Lista completa · CA Temporanei con contratto nel mese · Tasto destro (o doppio tap) su una riga: segnalibro «sono arrivato qui»</p>
                </div>
                <button type="button" className="tmd-btn tmd-btn--primary tmd-ranking__print" onClick={() => window.print()}>
                    <PrintIcon /> Stampa
                </button>
            </div>

            <section className="tmd-panel">
                <div className="tmd-ranking__search">
                    <input
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Cerca per nome, telefono o email…"
                        aria-label="Cerca in graduatoria"
                    />
                    <span className="tmd-ranking__count">{rows.length} medici</span>
                </div>

                <div className="tmd-ranking__tablewrap">
                    <table className="tmd-table">
                        <thead>
                            <tr>
                                <th className="is-num">Pos.</th>
                                <th>Medico</th>
                                <th>Categoria</th>
                                <th>Loc.</th>
                                <th className="is-num">N. graduatoria aziendale</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr
                                    key={row.id}
                                    className={`tmd-rankrow${bookmarkId === row.id ? ' is-bookmarked' : ''}`}
                                    tabIndex={0}
                                    onClick={() => handleRowClick(row)}
                                    onContextMenu={(e) => {
                                        e.preventDefault();
                                        toggleBookmark(row.id);
                                    }}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' || e.key === ' ') {
                                            e.preventDefault();
                                            openRow(row);
                                        }
                                    }}
                                >
                                    <td className="is-num">{row.position}</td>
                                    <td className="tmd-table__name">
                                        <span className="tmd-rankrow__name">{row.name}</span>
                                        <span className="tmd-rankrow__category">{row.category}</span>
                                    </td>
                                    <td className="tmd-table__muted is-detail">{row.category}</td>
                                    <td className="tmd-table__muted is-detail">{row.loc || '—'}</td>
                                    <td className="is-num is-detail">{row.ca_rank !== null ? row.ca_rank : '—'}</td>
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr><td colSpan={5} className="tmd-table__empty">Nessun medico trovato.</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </section>

            {selected && (
                <DoctorModal
                    row={selected}
                    sharing={sharing?.id === selected.id ? sharing : null}
                    copied={copied?.id === selected.id ? copied : null}
                    onShare={share}
                    onClose={() => setSelected(null)}
                />
            )}
        </div>
    );
}
