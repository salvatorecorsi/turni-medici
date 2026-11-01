import { useState } from '@wordpress/element';
import { Icon, dragHandle } from '@wordpress/icons';

export default function SortableList({ items, onReorder, renderItem }) {
    const [dragIndex, setDragIndex] = useState(null);

    const handleDragOver = (event, overIndex) => {
        event.preventDefault();
        if (dragIndex === null || dragIndex === overIndex) return;
        const next = [...items];
        const [moved] = next.splice(dragIndex, 1);
        next.splice(overIndex, 0, moved);
        setDragIndex(overIndex);
        onReorder(next);
    };

    return (
        <ul className="tm-sortable">
            {items.map((item, index) => (
                <li
                    key={item.id}
                    className={`tm-sortable__item${dragIndex === index ? ' is-dragging' : ''}`}
                    onDragOver={(event) => handleDragOver(event, index)}
                >
                    <span
                        className="tm-sortable__handle"
                        draggable
                        onDragStart={() => setDragIndex(index)}
                        onDragEnd={() => setDragIndex(null)}
                    >
                        <Icon icon={dragHandle} />
                    </span>
                    <span className="tm-sortable__rank">{index + 1}</span>
                    <span className="tm-sortable__body">{renderItem(item, index)}</span>
                </li>
            ))}
        </ul>
    );
}
