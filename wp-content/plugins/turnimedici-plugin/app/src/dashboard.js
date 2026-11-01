import { createRoot } from '@wordpress/element';
import App from './dashboard/App';
import './dashboard/style.scss';

const node = document.getElementById('tm-dashboard');
if (node) {
    createRoot(node).render(<App />);
}
