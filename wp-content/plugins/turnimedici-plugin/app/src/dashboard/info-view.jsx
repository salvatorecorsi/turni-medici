import { useState, useEffect, useMemo } from '@wordpress/element';
import { getGuides, getGuide, getGlossaryTerms, getGlossaryGroups } from './api';

const decode = (html) => {
    if (!html) return '';
    const el = document.createElement('textarea');
    el.innerHTML = html;
    return el.value;
};

const BookIcon = () => (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" /></svg>
);
const TermIcon = () => (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10" /><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
);

function GuideDetail({ id, onBack }) {
    const [guide, setGuide] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        setGuide(null);
        getGuide(id).then(setGuide).catch((err) => setError(err.message));
    }, [id]);

    return (
        <div className="tmv tmd-info">
            <div className="tmv-topline">
                <button type="button" className="tmv-back tmd-linkbtn" onClick={onBack}>‹ Tutte le informazioni</button>
            </div>
            {error ? (
                <div className="tmd-error">{error}</div>
            ) : !guide ? (
                <div className="tmd-loading">Caricamento…</div>
            ) : (
                <>
                    <h1 className="tmv-title tmd-info__title">{decode(guide.title.rendered)}</h1>
                    <article className="tmd-rich tm-article" dangerouslySetInnerHTML={{ __html: guide.content.rendered }} />
                </>
            )}
        </div>
    );
}

function TermDetail({ term, onBack }) {
    const full = term.meta?.tm_term_full || '';
    const aka = term.meta?.tm_term_aka || '';
    return (
        <div className="tmv tmd-info">
            <div className="tmv-topline">
                <button type="button" className="tmv-back tmd-linkbtn" onClick={onBack}>‹ Tutte le informazioni</button>
            </div>
            <span className="tmd-info__eyebrow">Glossario</span>
            <h1 className="tmv-title tmd-info__title">{decode(term.title.rendered)}</h1>
            {full && <p className="tmd-term__full">{full}</p>}
            {aka && <p className="tmd-term__aka">Anche detto: {aka}</p>}
            <article className="tmd-rich tm-article" dangerouslySetInnerHTML={{ __html: term.content.rendered }} />
        </div>
    );
}

export default function InfoView({ onBack }) {
    const [guides, setGuides] = useState(null);
    const [terms, setTerms] = useState(null);
    const [groups, setGroups] = useState([]);
    const [error, setError] = useState(null);
    const [selected, setSelected] = useState(null);
    const [query, setQuery] = useState('');

    useEffect(() => {
        Promise.all([getGuides(), getGlossaryTerms(), getGlossaryGroups()])
            .then(([g, t, gr]) => {
                setGuides(g);
                setTerms(t);
                setGroups(gr);
            })
            .catch((err) => setError(err.message));
    }, []);

    const grouped = useMemo(() => {
        if (!terms) return [];
        const q = query.trim().toLowerCase();
        const match = (term) => {
            if (!q) return true;
            const hay = `${decode(term.title.rendered)} ${term.meta?.tm_term_full || ''} ${term.meta?.tm_term_aka || ''}`.toLowerCase();
            return hay.indexOf(q) !== -1;
        };
        const filtered = terms.filter(match);
        const buckets = groups.map((group) => ({
            id: group.id,
            name: group.name,
            items: filtered.filter((term) => (term.tm_glossary_group || []).includes(group.id)),
        }));
        const orphans = filtered.filter((term) => !(term.tm_glossary_group || []).some((gid) => groups.find((g) => g.id === gid)));
        if (orphans.length) buckets.push({ id: 0, name: 'Altri termini', items: orphans });
        return buckets.filter((bucket) => bucket.items.length);
    }, [terms, groups, query]);

    if (error) return <div className="tmv tmd-info"><div className="tmd-error">{error}</div></div>;

    if (selected?.type === 'guide') {
        return <GuideDetail id={selected.id} onBack={() => setSelected(null)} />;
    }
    if (selected?.type === 'term') {
        const term = terms.find((t) => t.id === selected.id);
        if (term) return <TermDetail term={term} onBack={() => setSelected(null)} />;
    }

    return (
        <div className="tmv tmd-info">
            <div className="tmv-topline">
                <button type="button" className="tmv-back tmd-linkbtn" onClick={onBack}>‹ Dashboard</button>
            </div>

            <div className="tmd-info__head">
                <span className="tmd-info__eyebrow">Accordo Integrativo Regionale 2025</span>
                <h1 className="tmd-info__title">Capire il nuovo Accordo</h1>
                <p className="tmd-info__lead">Le regole della Medicina Generale toscana spiegate in chiaro: cosa cambia per te, le figure del Ruolo Unico, l'attività oraria, i compensi e le scadenze.</p>
            </div>

            <section className="tmd-info-section">
                <h2 className="tmd-info-section__title"><BookIcon /> Guide per argomento</h2>
                {guides === null ? (
                    <p className="tmd-empty">Caricamento…</p>
                ) : guides.length === 0 ? (
                    <p className="tmd-empty">Nessuna guida disponibile.</p>
                ) : (
                    <div className="tmd-guide-list">
                        {guides.map((guide) => (
                            <button key={guide.id} type="button" className="tmd-guide-card" onClick={() => setSelected({ type: 'guide', id: guide.id })}>
                                <span className="tmd-guide-card__title">{decode(guide.title.rendered)}</span>
                                <span className="tmd-guide-card__excerpt">{decode(guide.excerpt.rendered.replace(/<[^>]+>/g, '')).trim()}</span>
                                <span className="tmd-guide-card__arrow" aria-hidden="true">›</span>
                            </button>
                        ))}
                    </div>
                )}
            </section>

            <section className="tmd-info-section">
                <h2 className="tmd-info-section__title"><TermIcon /> Glossario</h2>
                <div className="tmd-info-search">
                    <input
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Cerca una sigla o un termine…"
                        aria-label="Cerca nel glossario"
                    />
                </div>
                {terms === null ? (
                    <p className="tmd-empty">Caricamento…</p>
                ) : grouped.length === 0 ? (
                    <p className="tmd-empty">Nessun termine corrisponde alla ricerca.</p>
                ) : (
                    grouped.map((bucket) => (
                        <div key={bucket.id} className="tmd-info-group">
                            <h3 className="tmd-info-group__title">{bucket.name}</h3>
                            <div className="tmd-term-grid">
                                {bucket.items.map((term) => (
                                    <button key={term.id} type="button" className="tmd-term-card" onClick={() => setSelected({ type: 'term', id: term.id })}>
                                        <span className="tmd-term-card__term">{decode(term.title.rendered)}</span>
                                        {term.meta?.tm_term_full && <span className="tmd-term-card__full">{term.meta.tm_term_full}</span>}
                                    </button>
                                ))}
                            </div>
                        </div>
                    ))
                )}
            </section>
        </div>
    );
}
