<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2014 DMK E-BUSINESS GmbH
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


tx_rnbase::load('tx_mksearch_tests_Util');
tx_rnbase::load('tx_rnbase_tests_BaseTestCase');

/**
 * Base Testcase
 *
 * @package tx_mksearch
 * @subpackage tx_mksearch_tests
 * @author Michael Wagner <michael.wagner@dmk-ebusiness.de>
 * @license http://www.gnu.org/licenses/lgpl.html
 *          GNU Lesser General Public License, version 3 or later
 */
abstract class tx_mksearch_tests_Testcase
	extends tx_rnbase_tests_BaseTestCase {

	protected $templaVoilaConfigBackup;

	/**
	 * setUp() = init DB etc.
	 */
	protected function setUp() {
		tx_mksearch_tests_Util::emptyAddRootlineFields();

		// set up hooks
		tx_mksearch_tests_Util::hooksSetUp();

		// WORKAROUND: phpunit seems to backup static attributes (in phpunit.xml)
		// from version 3.6.10 not before. I'm not completely
		// sure about that but from version 3.6.10 clearPageInstance is no
		// more neccessary to have the complete test suite succeed.
		// But this version is buggy. (http://forge.typo3.org/issues/36232)
		// as soon as this bug is fixed, we can use the new phpunit version
		// and dont need this anymore
		tx_mksearch_service_indexer_core_Config::clearPageInstance();

		// das devlog stört nur bei der Testausführung im BE und ist da auch
		// vollkommen unnötig
		tx_mksearch_tests_Util::disableDevlog();

		// set up tv
		if (tx_rnbase_util_Extensions::isLoaded('templavoila')) {
			$this->templaVoilaConfigBackup = $GLOBALS['TYPO3_LOADED_EXT']['templavoila'];
			$GLOBALS['TYPO3_LOADED_EXT']['templavoila'] = NULL;

			tx_mksearch_tests_Util::unloadTemplavoilaForTypo362OrHigher();
		}


	}

	/**
	 * tearDown() = destroy DB etc.
	 */
	protected function tearDown() {
		// tear down hooks
		tx_mksearch_tests_Util::hooksTearDown();

		// tear down tv
		if ($this->templaVoilaConfigBackup !== NULL) {
			$GLOBALS['TYPO3_LOADED_EXT']['templavoila'] = $this->templaVoilaConfigBackup;
			$this->templaVoilaConfigBackup = NULL;

			if (tx_rnbase_util_TYPO3::isTYPO62OrHigher()) {
				$extensionManagementUtility = new TYPO3\CMS\Core\Utility\ExtensionManagementUtility();
				$extensionManagementUtility->loadExtension('templavoila');
			}
		}

		tx_mksearch_tests_Util::resetAddRootlineFields();
	}

	/**
	 *
	 * @param string|array $extKey
	 * @param string $contentType
	 * @return tx_mksearch_model_IndexerDocumentBase|PHPUnit_Framework_MockObject_MockObject
	 */
	protected function getIndexDocMock($extKey, $contentType = NULL) {
		tx_rnbase::load('tx_mksearch_model_IndexerDocumentBase');
		if ($extKey instanceof tx_mksearch_interface_Indexer) {
			list($extKey, $contentType) = $extKey->getContentType();
		}

		return tx_rnbase::makeInstance(
			'tx_mksearch_model_IndexerDocumentBase',
			$extKey, $contentType
		);
	}

	/**
	 * @param array $record
	 * @param tx_mksearch_interface_Indexer $indexer
	 * @param string $tableName
	 * @param array $options
	 * @return tx_mksearch_model_IndexerDocumentBase|PHPUnit_Framework_MockObject_MockObject
	 */
	protected function getPreparedIndexDocMockByRecord(
		array $record,
		tx_mksearch_interface_Indexer $indexer,
		$tableName,
		$options = NULL
	) {
		$indexDoc = $this->getIndexDocMock($indexer);

		if (!is_array($options)) {
			$options = tx_mksearch_util_Misc::parseTsConfig(
				'{' . LF . $indexer->getDefaultTSConfig() . LF . '}'
			);
		}

		return $indexer->prepareSearchData(
			$tableName,
			$record,
			$indexDoc,
			$options
		);
	}

	/**
	 * checks a index doc, if there was a correct value
	 * @param tx_mksearch_interface_IndexerDocument $indexDoc
	 * @param string $fieldName
	 * @param string $expectedValue
	 */
	public static function assertIndexDocHasField(
		$indexDoc,
		$fieldName,
		$expectedValue
	) {
		$message = __METHOD__ . '("Line ' . __LINE__ . '"): ';
		self::assertInstanceOf(
			'tx_mksearch_interface_IndexerDocument',
			$indexDoc,
			$message . '$indexDoc has to be an instance of "tx_mksearch_interface_IndexerDocument" but "'
				. (is_object($indexDoc) ? get_class($indexDoc) : gettype($indexDoc)) . '" given.'
		);
		$indexData = $indexDoc->getData();
		self::assertTrue(
			is_array($indexData),
			$message . 'The data of $indexDoc has to be an array but "'
				. (is_object($indexData) ? get_class($indexData) : gettype($indexData)) . '" given.'
		);
		self::assertArrayHasKey(
			$fieldName,
			$indexData,
			__LINE__ . ': $indexData dows not contain the required field "' . $fieldName . '"'
		);
		$field = $indexData[$fieldName];
		self::assertInstanceOf(
			'tx_mksearch_interface_IndexerField',
			$field,
			$message . '"' . $fieldName . '" has to be an instance of "tx_mksearch_interface_IndexerField" but "'
				. (is_object($field) ? get_class($field) : gettype($field)) . '" given.'
		);
		self::assertSame(
			$expectedValue,
			$field->getValue(),
			$message . '"' . $fieldName . '" contains the wrong value. "' . $expectedValue . '" as expected but "' . $field->getValue() . '" given.'
		);
	}

}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/mksearch/tests/class.tx_mksearch_tests_Testcase.php']) {
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/mksearch/tests/class.tx_mksearch_tests_Testcase.php']);
}
