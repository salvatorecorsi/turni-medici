import { createRoot } from '@wordpress/element';
import StructuresApp from './structures/structures-app';

const container = document.getElementById('tm-structures-app');
if (container) {
    createRoot(container).render(<StructuresApp />);
}
