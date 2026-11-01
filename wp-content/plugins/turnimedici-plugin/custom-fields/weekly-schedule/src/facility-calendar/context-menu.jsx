export default function ContextMenu({ x, y, items, onClose }) {
    return (
        <div className="tm-ctx__overlay" onMouseDown={onClose} onContextMenu={(e) => { e.preventDefault(); onClose(); }}>
            <ul
                className="tm-ctx"
                style={{ top: y, left: x }}
                onMouseDown={(e) => e.stopPropagation()}
            >
                {items.map((item, index) => (
                    <li key={index}>
                        <button
                            type="button"
                            className={item.destructive ? 'is-destructive' : ''}
                            onClick={() => { item.onClick(); onClose(); }}
                        >
                            {item.label}
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}
