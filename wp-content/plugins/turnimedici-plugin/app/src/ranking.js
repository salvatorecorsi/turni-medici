import { createRoot } from '@wordpress/element';
import RankingApp from './ranking/ranking-app';

const container = document.getElementById('tm-ranking-app');
if (container) {
    createRoot(container).render(<RankingApp />);
}
