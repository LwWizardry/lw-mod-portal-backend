<?php

//TODO: Once this ends up on the production domain, move all error handling into a secure channel.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../../vendor/autoload.php';

use MP\DatabaseTables\TableModSummary;
use MP\SlimSetup;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

//Load environment variables:
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();
$dotenv->required(['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'PRODUCTION_FRONTEND_URL'])->notEmpty();

set_error_handler(function(
	int $errno,
	string $errstr,
	string $errfile,
	int $errline,
) {
	if($errno == E_WARNING) {
		throw new ErrorException(
			'Warning was raised in ' . $errfile . ' line ' . $errline . ' with message: ' . $errstr
		);
	}
	
	return false;
});

SlimSetup::setup();
//Any error above this point will result in a CORS issue in the browser. Mostly these are failed setup errors though.

SlimSetup::getSlim()->get('/mod/{name}[/]', function (Request $request, Response $response, array $args) {
	$mod_name = $args['name'];
	$meta_tags = [];
	//For now all mod names start with 'mod-', so do not even try to parse for a custom URL.
	if(preg_match("/^mod-[A-Za-z0-9_-]+$/", $mod_name) !== 1) {
		//Invalid mod name, refuse the request gracefully.
		
		$meta_tags['og:title'] = '404 Nothing here';
		$meta_tags['og:description'] = 'Mod does not exist as it has an invalid name.';
		return generate_html(
			$response,
			'Invalid mod name',
			'/mods',
			'List of mods',
			$meta_tags
		);
	}
	
	//Try to fetch the metadata for that mod.
	try {
		$identifier = substr($mod_name, 4);
		$modSummary = TableModSummary::getModFromIdentifier($identifier);
		//If mod does not exist, return a 404 preview.
		//TODO: Or if not visible (feature does not yet exist).
		if($modSummary === null) {
			$meta_tags['og:title'] = 'Mod 404';
			$meta_tags['og:description'] = 'Mod does not exist on Community Mod Portal.';
			return generate_html(
				$response,
				'Not existing mod',
				'/mods',
				'List of mods',
				$meta_tags
			);
		}
		
		//In case that the DB lookup delivers one mod, use this format:
		$title = 'Mod: ' . $modSummary->getTitle();
		$meta_tags['og:title'] = $title;
		$meta_tags['og:description'] = $modSummary->getCaption();
		$redirection_title = $title;
		if ($modSummary->getLogo() !== null) {
			$meta_tags['og:image'] = 'https://api-lwmods.ecconia.com/assets/logos/' . $modSummary->getIdentifier() . '/' . $modSummary->getLogo();
		}
	} catch (Throwable) {
		$title = 'Error loading mod';
		$meta_tags['og:title'] = 'Failed loading mod';
		$meta_tags['og:description'] = 'Mod information could not be loaded by backend.';
		$redirection_title = $mod_name;
		//TODO: Handle exception, as in send it somewhere. For now lets not bother.
	}
	
	//Regardless of fetching success, set things which are the same for either case.
	$redirection_target = '/mod-direct/' . $mod_name;
	//Ensure that the server does set the 'REQUEST_SCHEME' variable.
	$meta_tags['og:url'] = ($_SERVER['REQUEST_SCHEME'] ?? 'http') . "://$_SERVER[HTTP_HOST]/mod/$mod_name";
	
	return generate_html($response, $title, $redirection_target, $redirection_title, $meta_tags);
});

function generate_html(
	Response $response,
	string $title,
	string $redirection_target,
	string $redirection_title,
	array $meta_tags,
) : Response {
	$response->getBody()->write(
		<<<HTML
		<!DOCTYPE html>
		<head>
			<meta charset="UTF-8">
			<title>$title</title>
			<link rel="icon" type="image/x-icon" href="/assets/favicon.ico"><link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16x16.png"><link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png"><link rel="icon" type="image/png" sizes="48x48" href="/assets/favicon-48x48.png"><link rel="manifest" href="/assets/manifest.webmanifest"><meta name="mobile-web-app-capable" content="yes"><meta name="theme-color" content="#fff"><meta name="application-name"><link rel="apple-touch-icon" sizes="57x57" href="/assets/apple-touch-icon-57x57.png"><link rel="apple-touch-icon" sizes="60x60" href="/assets/apple-touch-icon-60x60.png"><link rel="apple-touch-icon" sizes="72x72" href="/assets/apple-touch-icon-72x72.png"><link rel="apple-touch-icon" sizes="76x76" href="/assets/apple-touch-icon-76x76.png"><link rel="apple-touch-icon" sizes="114x114" href="/assets/apple-touch-icon-114x114.png"><link rel="apple-touch-icon" sizes="120x120" href="/assets/apple-touch-icon-120x120.png"><link rel="apple-touch-icon" sizes="144x144" href="/assets/apple-touch-icon-144x144.png"><link rel="apple-touch-icon" sizes="152x152" href="/assets/apple-touch-icon-152x152.png"><link rel="apple-touch-icon" sizes="167x167" href="/assets/apple-touch-icon-167x167.png"><link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon-180x180.png"><link rel="apple-touch-icon" sizes="1024x1024" href="/assets/apple-touch-icon-1024x1024.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title"><link rel="apple-touch-startup-image" media="(device-width: 320px) and (device-height: 568px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)" href="/assets/apple-touch-startup-image-640x1136.png"><link rel="apple-touch-startup-image" media="(device-width: 320px) and (device-height: 568px) and (-webkit-device-pixel-ratio: 2) and (orientation: landscape)" href="/assets/apple-touch-startup-image-1136x640.png"><link rel="apple-touch-startup-image" media="(device-width: 375px) and (device-height: 667px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)" href="/assets/apple-touch-startup-image-750x1334.png"><link rel="apple-touch-startup-image" media="(device-width: 375px) and (device-height: 667px) and (-webkit-device-pixel-ratio: 2) and (orientation: landscape)" href="/assets/apple-touch-startup-image-1334x750.png"><link rel="apple-touch-startup-image" media="(device-width: 375px) and (device-height: 812px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" href="/assets/apple-touch-startup-image-1125x2436.png"><link rel="apple-touch-startup-image" media="(device-width: 375px) and (device-height: 812px) and (-webkit-device-pixel-ratio: 3) and (orientation: landscape)" href="/assets/apple-touch-startup-image-2436x1125.png"><link rel="apple-touch-startup-image" media="(device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" href="/assets/apple-touch-startup-image-1170x2532.png"><link rel="apple-touch-startup-image" media="(device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3) and (orientation: landscape)" href="/assets/apple-touch-startup-image-2532x1170.png"><link rel="apple-touch-startup-image" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)" href="/assets/apple-touch-startup-image-828x1792.png"><link rel="apple-touch-startup-image" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 2) and (orientation: landscape)" href="/assets/apple-touch-startup-image-1792x828.png"><link rel="apple-touch-startup-image" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" href="/assets/apple-touch-startup-image-1242x2688.png"><link rel="apple-touch-startup-image" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 3) and (orientation: landscape)" href="/assets/apple-touch-startup-image-2688x1242.png"><link rel="apple-touch-startup-image" media="(device-width: 414px) and (device-height: 736px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" href="/assets/apple-touch-startup-image-1242x2208.png"><link rel="apple-touch-startup-image" media="(device-width: 414px) and (device-height: 736px) and (-webkit-device-pixel-ratio: 3) and (orientation: landscape)" href="/assets/apple-touch-startup-image-2208x1242.png"><link rel="apple-touch-startup-image" media="(device-width: 428px) and (device-height: 926px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" href="/assets/apple-touch-startup-image-1284x2778.png"><link rel="apple-touch-startup-image" media="(device-width: 428px) and (device-height: 926px) and (-webkit-device-pixel-ratio: 3) and (orientation: landscape)" href="/assets/apple-touch-startup-image-2778x1284.png"><link rel="apple-touch-startup-image" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)" href="/assets/apple-touch-startup-image-1536x2048.png"><link rel="apple-touch-startup-image" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 2) and (orientation: landscape)" href="/assets/apple-touch-startup-image-2048x1536.png"><link rel="apple-touch-startup-image" media="(device-width: 810px) and (device-height: 1080px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)" href="/assets/apple-touch-startup-image-1620x2160.png"><link rel="apple-touch-startup-image" media="(device-width: 810px) and (device-height: 1080px) and (-webkit-device-pixel-ratio: 2) and (orientation: landscape)" href="/assets/apple-touch-startup-image-2160x1620.png"><link rel="apple-touch-startup-image" media="(device-width: 834px) and (device-height: 1194px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)" href="/assets/apple-touch-startup-image-1668x2388.png"><link rel="apple-touch-startup-image" media="(device-width: 834px) and (device-height: 1194px) and (-webkit-device-pixel-ratio: 2) and (orientation: landscape)" href="/assets/apple-touch-startup-image-2388x1668.png"><link rel="apple-touch-startup-image" media="(device-width: 834px) and (device-height: 1112px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)" href="/assets/apple-touch-startup-image-1668x2224.png"><link rel="apple-touch-startup-image" media="(device-width: 834px) and (device-height: 1112px) and (-webkit-device-pixel-ratio: 2) and (orientation: landscape)" href="/assets/apple-touch-startup-image-2224x1668.png"><link rel="apple-touch-startup-image" media="(device-width: 1024px) and (device-height: 1366px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)" href="/assets/apple-touch-startup-image-2048x2732.png"><link rel="apple-touch-startup-image" media="(device-width: 1024px) and (device-height: 1366px) and (-webkit-device-pixel-ratio: 2) and (orientation: landscape)" href="/assets/apple-touch-startup-image-2732x2048.png"><meta name="msapplication-TileColor" content="#fff"><meta name="msapplication-TileImage" content="/assets/mstile-144x144.png"><meta name="msapplication-config" content="/assets/browserconfig.xml"><link rel="yandex-tableau-widget" href="/assets/yandex-browser-manifest.json">
			<meta property="og:site_name" content="Logic World Community Mod Portal" />
		HTML);
	foreach ($meta_tags as $key => $value) {
		$response->getBody()->write(
			<<<HTML
				<meta property="$key" content="$value" />
			
			HTML);
	}
	$response->getBody()->write(
		<<<HTML
			<script>document.location.replace("$redirection_target");</script>
			<style>body{background-color:#181818; color:#9f9f9f} a{color:#00bd7e}</style>
		</head>
		<body>
			<p>$title</p>
			<p>
				You should be automatically redirected to <a href="$redirection_target">$redirection_title</a>
			</p>
			<p>
				In case it did not redirect you: Report a bug [unless you disabled JS].
			</p>
		</body>
		</html>
		
		HTML);
	return $response;
}

SlimSetup::run();
