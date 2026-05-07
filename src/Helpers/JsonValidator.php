<?php

namespace MP\Helpers;

use DateTime;
use DateTimeZone;
use Exception;
use JsonException;
use MP\ErrorHandling\InternalDescriptiveException;

class JsonValidator {
	//TODO: Add custom JSON exception type, to catch and wrap.
	public static function parseJson(string $input): array {
		try {
			return json_decode(
				$input,
				true,
				512,
				JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR
			);
		} catch (JsonException $e) {
			throw new InternalDescriptiveException('Failed to parse JSON with error ' . get_class($e) . '(' . $e->getCode() . '): "' . $e->getMessage() . '" with content: ' . $input);
		}
	}
	
	public static function hasKey(array $object, string $key): bool {
		return array_key_exists($key, $object);
	}
	
	public static function expectKey(array $object, string $key): mixed {
		if(!self::hasKey($object, $key)) {
			throw new InternalDescriptiveException('Missing key "' . $key . '" in JSON: ' . json_encode($object));
		}
		return $object[$key];
	}
	
	public static function getObject(array $object, string $key): array {
		$value = self::expectKey($object, $key);
		if(gettype($value) !== 'array') {
			throw new InternalDescriptiveException('Expected value of key "' . $key . '" of type "array", but got ' . gettype($value) . ' in JSON: ' . json_encode($object));
		}
		return $value;
	}
	
	public static function getString(array $object, string $key): string {
		$value = self::expectKey($object, $key);
		if(gettype($value) !== 'string') {
			throw new InternalDescriptiveException('Expected value of key "' . $key . '" of type "string", but got ' . gettype($value) . ' in JSON: ' . json_encode($object));
		}
		return $value;
	}
	
	public static function getStringNullable(array $object, string $key): null|string {
		$value = self::expectKey($object, $key);
		if($value !== null && gettype($value) !== 'string') {
			throw new InternalDescriptiveException('Expected value of key "' . $key . '" of type "string", but got ' . gettype($value) . ' in JSON: ' . json_encode($object));
		}
		return $value;
	}
	
	public static function getUInt(array $object, string $key): int {
		$value = self::expectKey($object, $key);
		if(gettype($value) !== 'integer') {
			throw new InternalDescriptiveException('Expected value of key "' . $key . '" of type "unsigned integer", but got ' . gettype($value) . ' in JSON: ' . json_encode($object));
		}
		if($value < 0) {
			throw new InternalDescriptiveException('Expected value of key "' . $key . '" to be positive, but got ' . $value . ' in JSON: ' . json_encode($object));
		}
		return $value;
	}
	
	public static function getDateTimeOptional(array $object, string $key): null|DateTime {
		$value = self::expectKey($object, $key);
		if($value === null) {
			return null;
		}
		if($value == 0) {
			return null;
		}
		if(gettype($value) === 'string') {
			// Current date format is '2026-05-06T20:01:33Z'
			$number = $value;
		} else if(gettype($value) === 'integer') {
			if($value <= 0) {
				throw new InternalDescriptiveException('Expected value of key "' . $key . '" of type "timestamp", but got negative value ' . $value . ' in JSON: ' . json_encode($object));
			}
			$number = '@' . $value;
		} else {
			throw new InternalDescriptiveException('Expected value of key "' . $key . '" of type "timestamp (number/string)", but got ' . gettype($value) . ' in JSON: ' . json_encode($object));
		}
		try {
			return new DateTime($number, new DateTimeZone('UTC'));
		} catch (Exception $e) {
			throw new InternalDescriptiveException('Could not parse date ' . $number . ': ' . $e->getMessage());
		}
	}
	
	public static function getDateTime(array $object, string $key): null|DateTime {
		$value = self::getDateTimeOptional($object, $key);
		if($value === null) {
			throw new InternalDescriptiveException('Expected value of key "' . $key . '" to be a valid date, but got "null or 0" in JSON: ' . json_encode($object));
		}
		return $value;
	}
	
	public static function isNotNull(array $object, string $key): bool {
		$value = self::expectKey($object, $key);
		return $value !== null;
	}
}
