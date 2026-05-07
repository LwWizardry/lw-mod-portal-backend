<?php

namespace MP\Handlers;

use MP\DatabaseTables\TableModDetails;
use MP\DatabaseTables\TableModSummary;
use MP\DatabaseTables\TableUser;
use MP\ErrorHandling\BadRequestException;
use MP\ErrorHandling\InternalDescriptiveException;
use MP\Helpers\FileSystemHelper;
use MP\Helpers\ImageWrapper;
use MP\Helpers\JsonValidator;
use MP\Helpers\QueryBuilder\QueryBuilder;
use MP\Helpers\UTF8Helper;
use MP\PDOWrapper;
use MP\ResponseFactory;
use MP\SlimSetup;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

class EditModHandler {
	public static function initializeRouteHandlers(): void {
		SlimSetup::getSlim()->post('/mod/post', self::addMod(...));
		SlimSetup::getSlim()->get('/mod/user-list', self::listModsOfUser(...));
		SlimSetup::getSlim()->post('/mod/edit', self::editMod(...));
	}
	
	public static function editMod(Request $request, Response $response): Response {
		//Get user making the request:
		$authToken = SlimSetup::expectAuthorizationHeader($request);
		$user = TableUser::fromSession($authToken);
		//At this point it is validated, that a logged-in user is making the request.
		
		//Parse data for this request:
		$content = $request->getBody()->getContents();
		$jsonContent = JsonValidator::parseJson($content);
		$jsonData = JsonValidator::getObject($jsonContent, 'data');
		
		$modIdentifier = JsonValidator::getString($jsonData, 'identifier');
		$newTitle = JsonValidator::getStringNullable($jsonData, 'newTitle');
		$newCaption = JsonValidator::getStringNullable($jsonData, 'newCaption');
		$newDescription = JsonValidator::getStringNullable($jsonData, 'newDescription');
		$newLinkSourceCode = JsonValidator::getStringNullable($jsonData, 'newLinkSourceCode');
		$newLogo = null;
		if(JsonValidator::isNotNull($jsonData, 'newLogo')) {
			$logoObject = JsonValidator::getObject($jsonData, 'newLogo');
			$newLogo = JsonValidator::getStringNullable($logoObject, 'data');
			if($newLogo === null) {
				$newLogo = false; //Use 'false' to state, that the logo shall be cleared/deleted.
			} else {
				$newLogo = new ImageWrapper($newLogo);
			}
		}
		$changeNothingBesidesLogo = $newTitle === null && $newCaption === null && $newDescription === null && $newLinkSourceCode === null;
		if($changeNothingBesidesLogo && $newLogo === null) {
			throw new BadRequestException('No change in request.');
		}
		//TBI: Is trimming like this sufficient?
		$newTitle = $newTitle !== null ? trim($newTitle) : null;
		$newCaption = $newCaption !== null ? trim($newCaption) : null;
		$newDescription = $newDescription !== null ? trim($newDescription) : null;
		$newLinkSourceCode = $newLinkSourceCode !== null ? trim($newLinkSourceCode) : null;
		//TBI: Error type, front-end should have caught this... (for all following checks)
		$result = self::checkTitleCaption($newTitle, $newCaption)
			?? self::checkMaxLength($newDescription, 10000, 'Description')
			?? self::checkMaxLength($newLinkSourceCode, 500, 'LinkSourceCode');
		if($result !== null) {
			return ResponseFactory::writeFailureMessage($response, $result);
		}
		if($newLinkSourceCode !== null && !preg_match('#^https?://([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}|[^/.]+\.[^/.0-9]+)(/.*)?$#', $newLinkSourceCode)) {
			return ResponseFactory::writeFailureMessage($response, 'Source code link must start with http(s):// followed by a domain or IP address.');
		}
		//At this point, the request is valid.
		
		//Fetch mod data, to check what has to be changed:
		$modDetails = TableModDetails::getModFromIdentifier($modIdentifier);
		if($modDetails === null) {
			throw new BadRequestException('Mod does not exist.');
		}
		if($modDetails->getUser()->getIdentifier() !== $user->getIdentifier()) {
			//TODO: Once a mod can be hidden. Check if it is public, and adjust the error accordingly.
			throw new BadRequestException('No permission to edit this mod.');
		}
		//Request may actually be performed - as mod exist and user has permissions for it.
		
		$onFailureDelete = null;
		$onSuccessDelete = null;
		$logoDBEntry = null;
		if($newLogo !== null) {
			//Steps up to the folder, that contains the 'src' folder.
			$root = AssetHandler::getAssetFolder() . 'logos/';
			if(!is_dir($root)) {
				throw new InternalDescriptiveException('Logos folder does not exist! Cannot store the logo anywhere.');
			}
			//Logo folder of this mod:
			$modLogoFolder = $root . $modIdentifier . '/';
			
			if($newLogo === false) {
				if($modDetails->getLogo() === null) {
					//Deleting image, while no image was set, ignore this request.
					$newLogo = null;
				} else {
					$logoToDelete = $modLogoFolder . $modDetails->getLogo();
					if(!file_exists($logoToDelete)) {
						throw new InternalDescriptiveException('Tried to delete mod logo ' . $modDetails->getLogo() . ', but it did not exist on disk!');
					}
					$onSuccessDelete = $logoToDelete;
					//Leave $logoDBEntry the same, as it is already null.
				}
			} else {
				//Do not wreck the server by making it too full, prevent damage:
				FileSystemHelper::checkFreeSpace($root);
				
				//Create the logo folder for this mod:
				if(!is_dir($modLogoFolder)) {
					mkdir($modLogoFolder);
				}
				
				//Construct targetFile path:
				$newLogoPath = $newLogo->getHash() . '.' . $newLogo->getExtension();
				$filePath = $modLogoFolder . $newLogoPath;
				
				//TODO: Validate size requirements! (Front-end dev has to come up with this...)
				
				if($modDetails->getLogo() === null) {
					$onFailureDelete = $filePath;
					$logoDBEntry = $newLogoPath;
					if(file_put_contents($filePath, $newLogo->getBytes()) === false) {
						throw new InternalDescriptiveException('Was not able to save logo!');
					}
				} else if($modDetails->getLogo() === $newLogoPath) {
					//Same image, do nothing!
					$newLogo = null;
					//TBI: Warn client?
				} else {
					$onSuccessDelete = $modLogoFolder . $modDetails->getLogo();
					$onFailureDelete = $filePath;
					$logoDBEntry = $newLogoPath;
					if(file_put_contents($filePath, $newLogo->getBytes()) === false) {
						throw new InternalDescriptiveException('Was not able to save logo!');
					}
				}
			}
		}
		
		//Logo was set to 'null' as no operation has to be performed, return early:
		if($changeNothingBesidesLogo && $newLogo === null) {
			return ResponseFactory::writeJsonData($response, [
				'image' => $modDetails->getLogo(),
			]); //Just return affirmative.
		}
		
		$builder = QueryBuilder::update('mods');
		if($newTitle !== null) {
			$builder->setValue('title', $newTitle);
		}
		if($newCaption !== null) {
			$builder->setValue('caption', $newCaption);
		}
		if($newDescription !== null) {
			$builder->setValue('description', $newDescription);
		}
		if($newLinkSourceCode !== null) {
			if(empty($newLinkSourceCode)) {
				//TBI: Maybe just always store as string?
				$newLinkSourceCode = null;
			}
			$builder->setValue('link_source_code', $newLinkSourceCode);
		}
		if($newLogo !== null) {
			$builder->setValue('logo_path', $logoDBEntry);
		}
		$builder->whereValue('identifier', $modIdentifier);
		try {
			$builder->execute();
		} catch (PDOException $e) {
			if(PDOWrapper::isUniqueConstrainViolation($e)) {
				return ResponseFactory::writeFailureMessage($response, 'Mod title is already used by another mod.');
			}
			try {
				if($onFailureDelete !== null) {
					if(file_exists($onFailureDelete)) {
						unlink($onFailureDelete); //TBI: Error handling?
					}
				}
			} catch (Throwable) {
				//TBI: Handle error?
			}
			throw $e;
		}
		//TBI: Update fields?
		
		if($onSuccessDelete !== null) {
			if(file_exists($onSuccessDelete)) {
				unlink($onSuccessDelete); //TBI: Error handling?
			}
		}
		
		return ResponseFactory::writeJsonData($response, [
			'image' => $newLogo !== null ? $logoDBEntry : $modDetails->getLogo(),
		]);
	}
	
	public static function addMod(Request $request, Response $response): Response {
		$authToken = SlimSetup::expectAuthorizationHeader($request);
		$user = TableUser::fromSession($authToken);
		//At this point it is validated, that a logged-in user is making the request.
		
		//Validate data sent by the client makes sense. (Where data?)
		$content = $request->getBody()->getContents();
		$jsonContent = JsonValidator::parseJson($content);
		$jsonData = JsonValidator::getObject($jsonContent, 'data');
		
		$title = JsonValidator::getString($jsonData, 'title');
		$caption = JsonValidator::getString($jsonData, 'caption');
		
		//TBI: Is trimming like this sufficient?
		$title = trim($title);
		$caption = trim($caption);
		//TBI: Error type, front-end should have caught this... (for all following checks)
		$result = self::checkTitleCaption($title, $caption);
		if($result !== null) {
			return ResponseFactory::writeFailureMessage($response, $result);
		}
		//At this point the request data is valid.
		
		$modSummary = TableModDetails::addNewMod($title, $caption, $user);
		if($modSummary === null) {
			return ResponseFactory::writeFailureMessage($response, 'A mod with a title like this already exists!');
		}
		
		//Mod entry in theory finished...
		
		return ResponseFactory::writeJsonData($response, [
			'identifier' => $modSummary->getIdentifier(),
		]);
	}
	
	public static function listModsOfUser(Request $request, Response $response): Response {
		$params = $request->getQueryParams();
		if(!array_key_exists('identifier', $params)) {
			return ResponseFactory::writeBadRequestError($response, 'Missing "identifier" query in URL.');
		}
		$identifier = $params['identifier'];
		if(empty($identifier)) {
			return ResponseFactory::writeBadRequestError($response, 'Missing "identifier" query value in URL.');
		}
		//Now the user identifier is known. Try getting a user for it:
		$user = TableUser::fromIdentifier($identifier);
		if($user === null) {
			return ResponseFactory::writeJsonData($response, []); //No mod.
			//return ResponseFactory::writeBadRequestError($response, 'TableUser does not exist.');
		}
		//Now the user in question is there...
		
		$mods = TableModSummary::getSummariesForUser($user);
		return ResponseFactory::writeJsonData($response, array_map(
			function ($mod) {
				return $mod->asFrontEndJSON();
			},
			$mods
		));
	}
	
	private static function checkTitleCaption(null|string $title, null|string $caption): null|string {
		if($title !== null) {
			if(!UTF8Helper::isUTF8($title)) {
				throw new BadRequestException('Invalid UTF-8 sequence for title');
			}
			$titleLength = mb_strlen($title, 'UTF-8');
			if($titleLength < 3) {
				return 'Title must have at least 3 letters.';
			}
			if($titleLength > 50) {
				return 'Title must not be longer than 50 letters.';
			}
		}
		if($caption !== null) {
			if(!UTF8Helper::isUTF8($caption)) {
				throw new BadRequestException('Invalid UTF-8 sequence for title');
			}
			$captionLength = mb_strlen($caption, 'UTF-8');
			if($captionLength < 10) {
				return 'Caption must have at least 10 letters.';
			}
			if($captionLength > 200) {
				return 'Caption must not be longer than 200 letters.';
			}
		}
		return null;
	}
	
	private static function checkMaxLength(null|string $value, int $max, string $type): null|string {
		if($value === null) {
			return null;
		}
		if(!UTF8Helper::isUTF8($value)) {
			throw new BadRequestException('Invalid UTF-8 sequence for ' . strtolower($type));
		}
		$length = mb_strlen($value, 'UTF-8');
		if($length > $max) {
			return $type . ' must not be longer than ' . $max . ' letters.';
		}
		return null;
	}
}
