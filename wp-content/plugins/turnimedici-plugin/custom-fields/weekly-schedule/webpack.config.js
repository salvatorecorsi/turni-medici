const path = require('path');
const defaultConfig = require(path.resolve(__dirname, '../../app/node_modules/@wordpress/scripts/config/webpack.config'));

module.exports = {
    ...defaultConfig,
    context: __dirname,
    entry: {
        index: path.resolve(__dirname, 'src/index.js'),
    },
    output: {
        ...defaultConfig.output,
        path: path.resolve(__dirname, 'build'),
    },
    resolve: {
        ...defaultConfig.resolve,
        modules: [
            path.resolve(__dirname, '../../app/node_modules'),
            'node_modules',
        ],
    },
};
