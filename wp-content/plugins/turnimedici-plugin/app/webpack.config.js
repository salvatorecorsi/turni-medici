const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
    ...defaultConfig,
    entry: {
        doctors: './src/doctors.js',
        ranking: './src/ranking.js',
        dashboard: './src/dashboard.js',
        schedules: './src/schedules.js',
        structures: './src/structures.js',
    },
};
