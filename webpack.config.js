const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

let WooCommerceDependencyExtractionWebpackPlugin;
try {
	WooCommerceDependencyExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );
} catch ( e ) {
	// Fallback if the WooCommerce plugin is not installed — use default WP one.
	WooCommerceDependencyExtractionWebpackPlugin = null;
}

const config = {
	...defaultConfig,
	entry: {
		index: './src/index.js',
	},
};

if ( WooCommerceDependencyExtractionWebpackPlugin ) {
	config.plugins = [
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new WooCommerceDependencyExtractionWebpackPlugin(),
	];
}

module.exports = config;
