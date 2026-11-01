import { useState, useEffect } from '@wordpress/element';
import { Spinner, Button, Notice, SelectControl } from '@wordpress/components';
import SortableList from './sortable-list';
import { getRanking, saveRanking } from './api';
import { TIEBREAKER_LABELS } from './constants';

export default function RankingApp() {
    const [rules, setRules] = useState(null);
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [dirty, setDirty] = useState(false);

    useEffect(() => {
        getRanking().then(setRules).catch((err) => setError(err.message));
    }, []);

    if (error) {
        return <Notice status="error" isDismissible={false}>{error}</Notice>;
    }
    if (!rules) {
        return <Spinner />;
    }

    const setCategories = (categories) => {
        setRules({ ...rules, categories });
        setDirty(true);
    };
    const setTiebreakers = (tiebreakers) => {
        setRules({ ...rules, tiebreakers });
        setDirty(true);
    };
    const setCaZones = (items) => {
        setRules({ ...rules, ca_zones: items.map((item) => item.name) });
        setDirty(true);
    };
    const updateLabel = (id, label) => {
        setCategories(rules.categories.map((category) => (category.id === id ? { ...category, label } : category)));
    };
    const categoryCount = (category) =>
        (category.qualifications || []).reduce((sum, qualification) => sum + (rules.counts?.[qualification] || 0), 0);

    const save = async () => {
        setSaving(true);
        try {
            const saved = await saveRanking(rules);
            setRules(saved);
            setDirty(false);
        } catch (err) {
            window.alert(err.message);
        } finally {
            setSaving(false);
        }
    };

    const tiebreakerItems = rules.tiebreakers.map((id) => ({ id }));
    const caZoneItems = (rules.ca_zones || []).map((name) => ({ id: name, name }));

    return (
        <div className="tm-ranking">
            <div className="tm-ranking__col">
                <h2>Ordine delle categorie</h2>
                <p className="description">
                    La priorità di chiamata: chi è più in alto sceglie prima. Le etichette sono solo nomi,
                    rinominabili senza toccare i dati. Il numero è quanti medici hanno quella qualifica.
                </p>
                <SortableList
                    items={rules.categories}
                    onReorder={setCategories}
                    renderItem={(category) => (
                        <>
                            <input
                                className="tm-ranking__label"
                                value={category.label}
                                onChange={(event) => updateLabel(category.id, event.target.value)}
                            />
                            <span className="tm-ranking__count">{categoryCount(category)} medici</span>
                        </>
                    )}
                />
            </div>

            <div className="tm-ranking__col">
                <h2>Ordine CA a parità di fascia</h2>
                <SelectControl
                    value={rules.ca_order || 'rank'}
                    options={[
                        { value: 'rank', label: 'Numero di graduatoria aziendale (consigliato)' },
                        { value: 'zone', label: 'Prima la località, poi il numero (in dismissione)' },
                    ]}
                    onChange={(value) => { setRules({ ...rules, ca_order: value }); setDirty(true); }}
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                />
                {(rules.ca_order || 'rank') === 'zone' ? (
                    <>
                        <p className="description">
                            Per le guardie mediche, a parità di fascia, prima conta la località e poi il numero di graduatoria aziendale.
                        </p>
                        <SortableList
                            items={caZoneItems}
                            onReorder={setCaZones}
                            renderItem={(item) => (
                                <>
                                    <span className="tm-ranking__zone">{item.name}</span>
                                    <span className="tm-ranking__count">{rules.ca_zone_counts?.[item.name] || 0} medici</span>
                                </>
                            )}
                        />
                    </>
                ) : (
                    <p className="description">
                        A parità di fascia conta solo il numero di graduatoria aziendale: l'ordine delle località non viene usato.
                    </p>
                )}
            </div>

            <div className="tm-ranking__col">
                <h2>Criteri di parità</h2>
                <p className="description">
                    A parità di categoria, lo spareggio segue questi criteri in cascata dopo le regole specifiche CA.
                </p>
                <SortableList
                    items={tiebreakerItems}
                    onReorder={(items) => setTiebreakers(items.map((item) => item.id))}
                    renderItem={(item) => TIEBREAKER_LABELS[item.id] || item.id}
                />
            </div>

            <div className="tm-ranking__save">
                {dirty && <span className="tm-ranking__dirty">Modifiche non salvate</span>}
                <Button variant="primary" onClick={save} isBusy={saving} disabled={!dirty || saving}>
                    Salva graduatoria
                </Button>
            </div>
        </div>
    );
}
