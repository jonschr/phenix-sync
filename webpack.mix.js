const mix = require('laravel-mix');
const globImporter = require('node-sass-glob-importer');

mix.sass('assets/css/phenixsync-styles.scss', 'dist/css', {
	sassOptions: {
		importer: globImporter(),
	},
});
