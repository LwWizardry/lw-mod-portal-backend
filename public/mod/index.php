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
	//For now all mod names start with 'mod-', so do not even try to parse for a custom URL.
	if(preg_match("/^mod-[A-Za-z0-9_-]+$/", $mod_name) !== 1) {
		//Invalid mod name, refuse the request gracefully.
		$title = 'Invalid mod name';
		
		$search_title = '404 Nothing here';
		$search_description = 'Mod does not exist as it has an invalid name.';
		
		$url_title = 'List of mods';
		$destination_url = '/mods';
	} else {
		$destination_url = '/mod-direct/' . $mod_name;
		$identifier = substr($mod_name, 4);
		
		//In case that the DB lookup fails, use this data:
		$title = 'Error loading mod';
		$search_title = 'Failed loading mod';
		$search_description = 'Mod information could not be loaded by backend.';
		$url_title = $mod_name;
		
		try {
			$modSummary = TableModSummary::getModFromIdentifier($identifier);
			//TODO: Or if not visible.
			if($modSummary === null) {
				$title = 'Not existing mod';
				$search_title = 'Mod 404';
				$search_description = 'Mod does not exist on Mod Portal.';
				//Keep URL title as is.
			} else {
				//In case that the DB lookup delivers one mod, use this format:
				$title = 'Mod: ' . $modSummary->getTitle();
				$search_title = $title;
				$search_description = $modSummary->getCaption();
				$url_title = $title;
			}
		} catch (Throwable) {
			//TODO: Handle exception, as in send it somewhere. For now lets not bother.
		}
	}
	
	//Old forwarding: <!--<script>window.location.href = "$destination_url";</script>-->
	// Bad as it adds a browser history entry...
	
	$response->getBody()->write(
		<<<HTML
		<!DOCTYPE html>
		<head>
			<meta charset="UTF-8">
			<title>$title</title>
			<meta property="og:title" content="$search_title">
			<meta property="og:description" content="$search_description">
			<script>document.location.replace("$destination_url");</script>
			<style>body{background-color:#181818; color:#9f9f9f} a{color:#00bd7e}</style>
		</head>
		<body>
			<p>$title</p>
			<p>
				You should be automatically redirected to <a href="$destination_url">$url_title</a>
			</p>
			<p>
				In case it did not redirect you: Report a bug [unless you disabled JS].
			</p>
		</body>
		</html>
		HTML);
	return $response;
});

SlimSetup::run();
