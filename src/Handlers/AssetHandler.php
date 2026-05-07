<?php

namespace MP\Handlers;

use MP\ErrorHandling\BadRequestException;
use MP\ErrorHandling\InternalDescriptiveException;
use MP\Helpers\Base32;
use MP\SlimSetup;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AssetHandler {
	
	public static function initializeRouteHandlers(): void {
		SlimSetup::getSlim()->get('/assets/logos/{modID}/{fileName}', self::logoGetter(...));
	}
	
	public static function getAssetFolder(): string {
		//Construct logos folder and check if it exists:
		$root = dirname(__FILE__) . '/../../assets/';
		if(!is_dir($root)) {
			throw new InternalDescriptiveException('Assets folder does not exist!');
		}
		return $root;
	}
	
	public static function logoGetter(Request $request, Response $response, array $args): Response {
		$modID = $args['modID'];
		if(!Base32::matchesIdentifier($modID)) {
			throw new BadRequestException('Invalid mod ID.');
		}
		$fileName = $args['fileName'];
		if(!preg_match('#^[a-z0-9]+\.(webp|gif|png|jpg)$#', $fileName)) {
			throw new BadRequestException('Invalid image name.');
		}
		
		//Construct logos folder and check if it exists:
		$root = self::getAssetFolder() . 'logos/';
		if(!is_dir($root)) {
			throw new InternalDescriptiveException('Logo folder does not exist!');
		}
		
		//Construct file name:
		$filePath = $root . $modID . '/' . $fileName;
		if(!file_exists($filePath)) {
			return $response->withStatus(404);
		}
		
		$mime = mime_content_type($filePath);
		if($mime === false) {
			throw new InternalDescriptiveException('Not able to read mime type from logo file.');
		}
		$response = $response->withHeader('Content-Type', $mime);
		$content = file_get_contents($filePath);
		if($content === false) {
			throw new InternalDescriptiveException('Failed to read logo file.');
		}
		$response->getBody()->write($content);
		return $response;
	}
}
