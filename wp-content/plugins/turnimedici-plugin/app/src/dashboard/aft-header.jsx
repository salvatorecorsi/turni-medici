const initials = (name) =>
    name
        .replace(/AFT/gi, '')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word.charAt(0))
        .join('')
        .toUpperCase();

export default function AftHeader({ aft }) {
    if (!aft) {
        return null;
    }
    return (
        <header className="tmd-aft">
            {aft.logo ? (
                <img className="tmd-aft__logo" src={aft.logo} alt={aft.name} />
            ) : (
                <span className="tmd-aft__logo tmd-aft__logo--ph">{initials(aft.name)}</span>
            )}
            <div className="tmd-aft__text">
                <span className="tmd-aft__eyebrow">Sei associato a</span>
                <h1 className="tmd-aft__name">{aft.name}</h1>
            </div>
        </header>
    );
}
