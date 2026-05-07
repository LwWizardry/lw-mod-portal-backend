<?php

namespace MP\LwApi;

use MP\ErrorHandling\InternalDescriptiveException;
use MP\Helpers\JsonValidator;

class LWBackend {
	/**
	 * @return LWComment[]
	 */
	public static function queryCommentsForPost(string $postID): array {
		$query = 'query GetComments($objid:String!){comments(objid:$objid){id createdat editedat body author{id username picture flair}}}';
		$queryResponse = self::queryFromLogicWorldBackend($query, [
			'objid' => $postID,
		]);
		
		try {
			$rootObject = JsonValidator::parseJson($queryResponse);
			if(JsonValidator::hasKey($rootObject, 'error')) {
				throw new InternalDescriptiveException('API returned error: ' . $queryResponse);
			}
			
			$dataObject = JsonValidator::getObject($rootObject, 'data');
			$commentsObject = JsonValidator::getObject($dataObject, 'comments');
			
			$comments = [];
			foreach ($commentsObject as $commentObject) {
				$comment_id = JsonValidator::getString($commentObject, 'id');
				$comment_body = JsonValidator::getString($commentObject, 'body');
				$comment_author = JsonValidator::getObject($commentObject, 'author');
				$comment_createdAt = JsonValidator::getDateTime($commentObject, 'createdat');
				$comment_editedAt = JsonValidator::getDateTimeOptional($commentObject, 'editedat');
				$author_id = JsonValidator::getUInt($comment_author, 'id');
				$author_username = JsonValidator::getString($comment_author, 'username');
				$author_picture = JsonValidator::getString($comment_author, 'picture');
				$author_flair = JsonValidator::getString($comment_author, 'flair');
				//Convert empty strings to null, as that is easier to process:
				if(empty($author_picture)) {
					$author_picture = null;
				}
				if(empty($author_flair)) {
					$author_flair = null;
				}
				
				$author = new LWAuthor($author_id, $author_username, $author_picture, $author_flair);
				$comment = new LWComment($comment_id, $comment_body, $author, $comment_createdAt, $comment_editedAt);
				$comments[] = $comment;
			}
			return $comments;
		} catch (InternalDescriptiveException $e) {
			throw new InternalDescriptiveException('While getting comments of post ' . $postID . ' an exception happened:' . PHP_EOL . $e->getMessage());
		}
	}
	
	public static function queryFromLogicWorldBackend(string $query, array $variables): string {
		return self::performGraphQueryLanguage('https://logicworld.net/graphql', $query, $variables);
	}
	
	public static function performGraphQueryLanguage(string $url, string $query, array $variables): string {
		$json = '{"query":"' . $query . '","variables":' . json_encode($variables) . '}';
		return self::doCurlPostJsonRequest($url, $json);
	}
	
	public static function doCurlPostJsonRequest(string $url, string $content): string {
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
			],
			CURLOPT_POSTFIELDS => $content,
		]);
		$curlResponse = curl_exec($ch);
		if($curlResponse === FALSE) {
			$curlError = curl_error($ch);
			throw new InternalDescriptiveException('Failed to execute post request to "' . $url . '" with data "' . $content . '" because: ' . $curlError);
		}
		if(gettype($curlResponse) !== "string") {
			throw new InternalDescriptiveException('Failed to execute post request to "' . $url . '" with data "' . $content . '" because return type was not string but: ' . gettype($curlResponse));
		}
		return $curlResponse;
	}
}
