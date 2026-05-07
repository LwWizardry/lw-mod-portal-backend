<?php

//TODO: Once this ends up on the production domain, move all error handling into a secure channel.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

use MP\Handlers\HandlerList;
use MP\SlimSetup;

//Load environment variables:
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
$dotenv->required(['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'PRODUCTION_FRONTEND_URL', 'FREE_SPACE'])->notEmpty();

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
HandlerList::initializeRouteHandlers();
SlimSetup::run();
