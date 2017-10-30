<?php

if ( !defined( 'MEDIAWIKI' ) ) {
	echo "WindowsAzureStorage extension\n";
	exit( 1 );
}

$dir = dirname( __FILE__ ) . '/';

$wgExtensionCredits['other'][] = array(
	'path'           => __FILE__,
	'name'           => 'WindowsAzureStorage',
	'author'         => array( '[http://www.hallowelt.biz Hallo Welt! Medienwerkstatt GmbH]', 'Markus Glaser', 'Robert Vogel' ),
	'url'            => 'https://www.mediawiki.org/wiki/Extension:WindowsAzureStorage',
	'version'        => '1.1.0',
	'descriptionmsg' => 'windowsazurestorage-desc',
);

$wgMessagesDirs['WindowsAzureStorage'] = __DIR__ . '/i18n';

$wgAutoloadClasses['WindowsAzureFileBackend'] = $dir . 'WindowsAzureFileBackend.php';
$wgAutoloadClasses['AzureFileBackendList'] = $dir . 'WindowsAzureFileBackend.php';
$wgAutoloadClasses['AzureFileBackendDirList'] = $dir . 'WindowsAzureFileBackend.php';
$wgAutoloadClasses['AzureFileBackendFileList'] = $dir . 'WindowsAzureFileBackend.php';
