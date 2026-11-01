import { createRoot } from '@wordpress/element';
import SchedulesApp from './schedules/schedules-app';

const container = document.getElementById('tm-schedules-app');
if (container) {
    createRoot(container).render(<SchedulesApp />);
}
