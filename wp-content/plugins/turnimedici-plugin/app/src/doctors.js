import { createRoot } from '@wordpress/element';
import DoctorsApp from './doctors/doctors-app';

const container = document.getElementById('tm-doctors-app');
if (container) {
    createRoot(container).render(<DoctorsApp />);
}
