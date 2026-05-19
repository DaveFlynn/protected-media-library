<?php
// Hand-written (no build step). Lists the wp-* packages index.js uses so WP
// enqueues them as dependencies before our script.
return [
	'dependencies' => [
		'wp-blocks',
		'wp-element',
		'wp-block-editor',
		'wp-components',
		'wp-i18n',
		'wp-api-fetch',
		'pml-picker',
	],
	'version'      => '0.1.0',
];
