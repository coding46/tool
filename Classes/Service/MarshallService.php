<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013 Claus Due <claus@wildside.dk>, Wildside A/S
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * DomainObject Marshaling Service
 *
 * A (slower) replacement for using serialize() on Extbase
 * DomainObjects. Please note that this Service is significantly
 * slower than serialization and native (fx. WDDX) marshaling -
 * but it IS very much compatible with Extbase DomainObjects.
 *
 * Serialisation of DomainObjects is complicated in Extbase
 * extensions. DateTime objects cannot be serialized,
 * ObjectStorages may present problems - and worst of all,
 * serialize() has severe issues with Unicode support, reporting
 * incorrect string lengths which cause unserialize() to fail
 * _silently_ (E_NOTICE is given with an offset that causes the
 * error).
 *
 * This Service aims to make all of the above a non-issue while
 * also enabling byte-safe transmission of the serialised objects.
 * This Service...
 *
 * - Does NOT support marshaling Closures. Never marshal Closures!
 * - Serializes to a JSON structure with each object nested in
 *   a small meta-configuration describing class name etc.
 * - Encodes unicode characters as escaped byte sequences which
 *   are much safer for transmission.
 * - Avoids NULL-bytes in the serialised representation.
 * - Supports recursive objects.
 * - Supports ObjectStorage and inflation hereof.
 * - Serialises private and protected property values as well.
 * - Preserves every UID and clean states across marshaling.
 * - Skips properties annotated with a @dontmarshal tag signature.
 *
 * Can in theory be used to store "snapshots" of DomainObjects with
 * every single related object instance and DateTime, UID etc. which
 * can be inflated and ->update()'ed via a matching Repository. But
 * its primary use is the byte-safe network marshaled transmission
 * of complex Extbase DomainObject instances.
 *
 * PLEASE NOTE: if the output is used in JSON, private and protected
 * property values become exposed with no way of determining the
 * original access scope. This could potentially pose problems and/or
 * present a security risk!
 *
 * The Service has a built-in safety feature which allows inflation
 * of related objects ONLY when the class of those objects are valid
 * according to the ClassReflection of the original class. By also
 * specifying a (list of) allowed root classes you can completely
 * secure the inflation process.
 *
 * Output is safe for use with SOAP, XML-RPC, JSON (requires a JS-
 * based manual inflation due to the use of meta-nesting of objects)
 * etc. and can be compressed using any compression routine.
 *
 * @author Claus Due, Wildside A/S
 * @package Tool
 * @subpackage Service
 */
class Tx_Tool_Service_MarshallService implements t3lib_Singleton {

	const TYPE_CLOSURE = 'Closure';
	const TYPE_DATETIME = 'DateTime';
	const TYPE_DOMAINOBJECT = 'DomainObject';
	const TYPE_ARRAY = 'Array';
	const TYPE_ARRAYOBJECT = 'ArrayObject';
	const TYPE_OBJECT = 'Object';
	const TYPE_OBJECTSTORAGE = 'ObjectStorage';

	/**
	 * @var array
	 */
	protected $unsupportedTypes = array(
		self::TYPE_CLOSURE
	);

	/**
	 * @var Tx_Extbase_Object_ObjectManagerInterface
	 */
	protected $objectManager;

	/**
	 * @param Tx_Extbase_Object_ObjectManagerInterface $objectManager
	 */
	public function injectObjectManager(Tx_Extbase_Object_ObjectManagerInterface $objectManager) {
		$this->objectManager = $objectManager;
	}

	/**
	 * Marshall (deflate, encode) an object instance down to a JSON-based structure.
	 *
	 * Throws a RuntimeException if trying to marshall unsupported types (i.e. Closures)
	 *
	 * @param object $object
	 * @return string
	 * @throws RuntimeException
	 */
	public function marshall($object) {
		ini_set('memory_limit', '2048M');
		$encountered = array();
		$marshaled = $this->deflatePropertyValue($object, $encountered);
		$encoded = serialize($marshaled);
		return $encoded;
	}

	/**
	 * Unmarshals (inflates, decodes) a JSON-based structure up to an object instance.
	 *
	 * @param string $string
	 * @param mixed $allowedRootClassOrClasses A string class name or an array of ROOT OBJECT class names which are permitted
	 * @return object|NULL
	 * @throws RuntimeException
	 */
	public function unmarshall($string, $allowedRootClassOrClasses = NULL) {
		$string = trim($string);
		$decoded = unserialize($string);
		if (isset($decoded['class']) === FALSE) {
			return NULL;
		}
		$rootClassName = $decoded['class'];
		if (is_array($allowedRootClassOrClasses) === TRUE) {
			$rootClassIsPermitted = in_array($rootClassName, $allowedRootClassOrClasses);
		} else {
			$rootClassIsPermitted = ($rootClassName === $allowedRootClassOrClasses || $allowedRootClassOrClasses === NULL);
		}
		if ($rootClassIsPermitted === FALSE) {
			throw new RuntimeException('Attempt to unmarshall a disallowed root object class: "' . $rootClassName . '".', 1358284604);
		}
		$encounteredObjectInstancesForReuse = array();
		$unmarshaled = $this->inflatePropertyValue($decoded, $encounteredObjectInstancesForReuse);
		return $unmarshaled;
	}

	/**
	 * Does $propertyName on $instance contain a data type which supports deflation?
	 *
	 * @param object Instance of an object, DomainObject included
	 * @param string $propertyName String name of property on DomainObject instance which is up for assertion
	 * @return boolean
	 * @throws RuntimeException
	 */
	protected function assertSupportsDeflation($instance, $propertyName) {
		return TRUE;
	}

	/**
	 * May $propertyName on $instance be deflated according to doc comment tags and instance type (Closures not permitted)?
	 *
	 * @param object $instance Instance of an object, DomainObject included
	 * @param string $propertyName String name of property on DomainObject instance which is up for assertion
	 * @return boolean
	 * @throws RuntimeException
	 */
	protected function assertAllowsDeflation($instance, $propertyName) {
		return TRUE;
	}

	/**
	 * May $propertyName on $className be inflated if target is a $propertyType instance and doc comment tags allow?
	 *
	 * @param string $className String class name of the class being inflated
	 * @param string $propertyName String name of property being inflated on class
	 * @param string $propertyType String type of the property being inflated according to input, asserted against Reflection
	 * @return boolean
	 */
	protected function assertAllowsInflation($className, $propertyName, $propertyType) {
		return TRUE;
	}

	/**
	 * Does the target $propertyName on $className contain a data type which supports inflation?
	 *
	 * @param string $className String class name of the class being inflated
	 * @param string $propertyName String name of property being inflated on class
	 * @param string $propertyType String type of the property being inflated according to input, asserted against blacklist
	 * @return boolean
	 */
	protected function assertSupportsInflation($className, $propertyName, $propertyType) {
		return TRUE;
	}

	/**
	 * Returns the string object class name of $property on $instanceOrClassName - or NULL if target $property is not of type object.
	 *
	 * @param mixed $instanceOrClassName
	 * @param string $propertyName
	 * @return string|NULL
	 */
	protected function assertTargetInstanceClassName($instanceOrClassName, $propertyName) {
		return NULL;
	}

	/**
	 * Inflates a single value $propertyValue by up-casting it to an instance/variable of type $propertyType.
	 *
	 * @param array $metaConfigurationAndDeflatedValue The deflated configuration and value
	 * @param array $encounteredClassesIndexedBySplHash A cumulative array of encountered objects indexed by SPL hash
	 * @return mixed
	 */
	protected function inflatePropertyValue(array $metaConfigurationAndDeflatedValue, array &$encounteredClassesIndexedBySplHash) {
		$propertyType = $metaConfigurationAndDeflatedValue['type'];
		$propertyValue = $metaConfigurationAndDeflatedValue['value'];
		$unmarshaled = $propertyValue;
		if ($propertyType === self::TYPE_DATETIME) {
			$unmarshaled = $this->inflateDateTime($propertyValue);
		} elseif ($propertyType === self::TYPE_DOMAINOBJECT) {
			$unmarshaled = $this->inflateObject($metaConfigurationAndDeflatedValue, $encounteredClassesIndexedBySplHash);
		} elseif ($propertyType === self::TYPE_ARRAYOBJECT || $propertyType === self::TYPE_OBJECTSTORAGE) {
			$unmarshaled = $this->inflateArrayObject($metaConfigurationAndDeflatedValue, $encounteredClassesIndexedBySplHash);
		} elseif ($propertyType === self::TYPE_OBJECT) {
			$unmarshaled = $this->inflateObject($metaConfigurationAndDeflatedValue, $encounteredClassesIndexedBySplHash);
		} elseif (is_array($propertyValue) === TRUE) {
			$unmarshaled = $this->inflateArray($metaConfigurationAndDeflatedValue, $encounteredClassesIndexedBySplHash);
		}
		return $unmarshaled;
	}

	/**
	 * Deflates a single instance/variable into a meta-information-wrapped deflated value (array of meta plus deflated value).
	 *
	 * @param mixed $propertyValue The complex-type inflated/original instance or variable
	 * @param array $encounteredClassesIndexedBySplHash A cumulative array of encountered objects indexed by SPL hash
	 * @return array
	 */
	protected function deflatePropertyValue($propertyValue, array &$encounteredClassesIndexedBySplHash) {
		$metaInformationAndDeflatedValue = array();
		if ($propertyValue instanceof Tx_Extbase_DomainObject_DomainObjectInterface) {
			$metaInformationAndDeflatedValue['type'] = self::TYPE_DOMAINOBJECT;
			$metaInformationAndDeflatedValue['hash'] = spl_object_hash($propertyValue);
			$metaInformationAndDeflatedValue['class'] = get_class($propertyValue);
			$metaInformationAndDeflatedValue['value'] = $this->deflateObject($propertyValue, $encounteredClassesIndexedBySplHash);
		} elseif ($propertyValue instanceof DateTime) {
			$metaInformationAndDeflatedValue['type'] = self::TYPE_DATETIME;
			$metaInformationAndDeflatedValue['value'] = $this->deflateDateTime($propertyValue);
		} elseif ($propertyValue instanceof Tx_Extbase_Persistence_ObjectStorage) {
			$metaInformationAndDeflatedValue['type'] = self::TYPE_OBJECTSTORAGE;
			$metaInformationAndDeflatedValue['class'] = get_class($propertyValue);
			$metaInformationAndDeflatedValue['value'] = $this->deflateArray(iterator_to_array($propertyValue), $encounteredClassesIndexedBySplHash);
		} elseif ($propertyValue instanceof ArrayObject) {
			$metaInformationAndDeflatedValue['type'] = self::TYPE_ARRAYOBJECT;
			$metaInformationAndDeflatedValue['hash'] = spl_object_hash($propertyValue);
			$metaInformationAndDeflatedValue['class'] = get_class($propertyValue);
			$metaInformationAndDeflatedValue['value'] = $this->deflateArrayObject($propertyValue, $encounteredClassesIndexedBySplHash);
		} elseif (is_array($propertyValue) === TRUE) {
			$metaInformationAndDeflatedValue['type'] = self::TYPE_ARRAY;
			$metaInformationAndDeflatedValue['value'] = $this->deflateArray($propertyValue, $encounteredClassesIndexedBySplHash);
		} elseif (is_object($propertyValue) === TRUE) {
			$metaInformationAndDeflatedValue['type'] = self::TYPE_OBJECT;
			$metaInformationAndDeflatedValue['class'] = get_class($propertyValue);
			$metaInformationAndDeflatedValue['value'] = $this->deflateObject($propertyValue, $encounteredClassesIndexedBySplHash);
		} else {
			$metaInformationAndDeflatedValue['type'] = gettype($propertyValue);
			$metaInformationAndDeflatedValue['value'] = $propertyValue;
		}
		return $metaInformationAndDeflatedValue;
	}

	/**
	 * Deflates a DateTime value down to a UNIX timestamp (negative supported, 64-bit safe) integer.
	 *
	 * @param DateTime $dateTime
	 * @return integer
	 */
	protected function deflateDateTime(DateTime $dateTime) {
		$timestamp = $dateTime->format('U');
		return $timestamp;
	}

	/**
	 * Inflates a deflated "meta-information-plus-deflated-value" array up to a DateTime instance.
	 *
	 * @param array $metaConfigurationAndDeflatedValue The deflated configuration and value
	 * @return DateTime
	 */
	protected function inflateDateTime(array $metaConfigurationAndDeflatedValue) {
		$timestamp = $metaConfigurationAndDeflatedValue['value'];
		$dateTime = DateTime::createFromFormat('U', $timestamp);
		return $dateTime;
	}

	/**
	 * Deflates a DomainObject instance down to an array of meta-configuration and deflated value
	 *
	 * @param object $object
	 * @param array $encounteredClassesIndexedBySplHash A cumulative array of encountered objects indexed by SPL hash
	 * @return array
	 * @throws RuntimeException
	 */
	protected function deflateObject($object, array &$encounteredClassesIndexedBySplHash) {
		$className = get_class($object);
		$objectReflection = new ReflectionObject($object);
		$marshaled = array();
		$hash = spl_object_hash($object);
		if (isset($encounteredClassesIndexedBySplHash[$hash]) === TRUE) {
			return $hash;
		}
		$encounteredClassesIndexedBySplHash[$hash] = $hash;
		foreach ($objectReflection->getProperties() as $propertyReflection) {
			$propertyName = $propertyReflection->getName();
			$supportsDeflation = $this->assertSupportsDeflation($object, $propertyName);
			if ($supportsDeflation === FALSE) {
				throw new RuntimeException('Attempt to marshal an unsupported type (property "' .
					$propertyName . '" on class "' . $className . '")', 1358282768);
			}
			$allowsDeflation = $this->assertAllowsDeflation($object, $propertyName);
			if ($allowsDeflation) {
				$propertyReflection->setAccessible(TRUE);
				$propertyValue = $propertyReflection->getValue($object);
				$metaConfigurationAndDeflatedValue = $this->deflatePropertyValue($propertyValue, $encounteredClassesIndexedBySplHash);
				$marshaled[$propertyName] = $metaConfigurationAndDeflatedValue;
			}
		}
		return $marshaled;
	}

	/**
	 * Inflates a deflated "meta-information-plus-deflated-value" array up to a DomainObject instance.
	 *
	 * @param array $metaConfigurationAndDeflatedValue The deflated configuration and value
	 * @param array $encounteredClassesIndexedBySplHash A cumulative array of encountered objects indexed by SPL hash
	 * @return Tx_Extbase_DomainObject_DomainObjectInterface
	 */
	protected function inflateObject(array $metaConfigurationAndDeflatedValue, array &$encounteredClassesIndexedBySplHash) {
		if (is_string($metaConfigurationAndDeflatedValue['value'])) {
			$possibleHash = $metaConfigurationAndDeflatedValue['value'];
			if (isset($encounteredClassesIndexedBySplHash[$possibleHash]) === TRUE) {
				return $encounteredClassesIndexedBySplHash[$possibleHash];
			}
			return NULL;
		}
		$className = $metaConfigurationAndDeflatedValue['class'];
		$hash = $metaConfigurationAndDeflatedValue['hash'];
		$instance = $this->objectManager->create($className);
		$objectReflection = new ReflectionObject($instance);
		$encounteredClassesIndexedBySplHash[$hash] = $instance;
		foreach ($metaConfigurationAndDeflatedValue['value'] as $propertyName => $propertyMetaConfigurationAndDeflatedValue) {
			$propertyReflection = $objectReflection->getProperty($propertyName);
			$propertyReflection->setAccessible(TRUE);
			$propertyMetaConfigurationAndDeflatedValue = $metaConfigurationAndDeflatedValue['value'][$propertyName];
			$inflatedValue = $this->inflatePropertyValue($propertyMetaConfigurationAndDeflatedValue, $encounteredClassesIndexedBySplHash);
			$propertyReflection->setValue($instance, $inflatedValue);
			$propertyReflection->setAccessible(FALSE);
		}
		return $instance;
	}

	/**
	 * Deflates an ArrayObject into a "meta-information-plus-simple-array" array. Also deflates all members of the ArrayAccess
	 * instance into the appropriate types while also respecting doc comment annotations.
	 *
	 * @param ArrayObject $arrayObject An instance of a ArrayObject source
	 * @param array $encounteredClassesIndexedBySplHash A cumulative array of encountered objects indexed by SPL hash
	 * @return array
	 */
	protected function deflateArrayObject(ArrayObject $arrayObject, array &$encounteredClassesIndexedBySplHash) {
		return $this->deflateArray(iterator_to_array($arrayObject, TRUE), $encounteredClassesIndexedBySplHash);
	}

	/**
	 * Inflates a "meta-information-plus-simple-array" up to the configured type of ArrayObject instance. Also inflates all
	 * nested, deflated configurations up into members on the ArrayObject instance, using the appropriate inflation method.
	 *
	 * @param array $metaConfigurationAndDeflatedValue
	 * @param array $encounteredClassesIndexedBySplHash A cumulative array of encountered objects indexed by SPL hash
	 * @return ArrayObject
	 */
	protected function inflateArrayObject(array $metaConfigurationAndDeflatedValue, array &$encounteredClassesIndexedBySplHash) {
		$className = $metaConfigurationAndDeflatedValue['class'];
		if (is_string($metaConfigurationAndDeflatedValue['value']) === TRUE) {
			$possibleHash = $metaConfigurationAndDeflatedValue['value'];
			if (isset($encounteredClassesIndexedBySplHash[$possibleHash]) === TRUE) {
				return $encounteredClassesIndexedBySplHash[$possibleHash];
			}
			return NULL;
		}
		$instance = $this->objectManager->create($className);
		foreach ($metaConfigurationAndDeflatedValue['value'] as $index => $memberMetaConfigurationAndDeflatedValue) {
			$inflatedMember = $this->inflatePropertyValue($memberMetaConfigurationAndDeflatedValue, $encounteredClassesIndexedBySplHash);
			if ($instance instanceof Tx_Extbase_Persistence_ObjectStorage) {
				$instance->attach($inflatedMember);
			} elseif ($instance instanceof ArrayAccess || is_array($instance) === TRUE) {
				$instance[$index] = $inflatedMember;
			}
		}
		return $instance;
	}

	/**
	 * Deflates an array down into a "meta-information-plus-simple-array" array. Also deflates all members of the array into the
	 * appropriate types while also respecting doc comment annotations.
	 *
	 * @param array $array An array source
	 * @param array $encounteredClassesIndexedBySplHash A cumulative array of encountered objects indexed by SPL hash
	 * @return array
	 */
	protected function deflateArray(array $array, array &$encounteredClassesIndexedBySplHash) {
		$deflated = array();
		foreach ($array as $index => $member) {
			$memberMetaConfigurationAndDeflatedValue = $this->deflatePropertyValue($member, $encounteredClassesIndexedBySplHash);
			$deflated[$index] = $memberMetaConfigurationAndDeflatedValue;
		}
		return $deflated;
	}

	/**
	 * Inflates a "meta-information-plus-simple-array" up to the configured type of ArrayAccess instance. Also inflates all
	 * nested, deflated configurations up into members on the ArrayAccess instance, using the appropriate inflation method.
	 *
	 * @param array $metaConfigurationAndDeflatedValue
	 * @param array $encounteredClassesIndexedBySplHash A cumulative array of encountered objects indexed by SPL hash
	 * @return array
	 */
	protected function inflateArray(array $metaConfigurationAndDeflatedValue, array &$encounteredClassesIndexedBySplHash) {
		$array = array();
		foreach ($metaConfigurationAndDeflatedValue['value'] as $index => $memberMetaConfigurationAndDeflatedValue) {
			$array[$index] = $this->inflatePropertyValue($memberMetaConfigurationAndDeflatedValue, $encounteredClassesIndexedBySplHash);
		}
		return $array;
	}

}
