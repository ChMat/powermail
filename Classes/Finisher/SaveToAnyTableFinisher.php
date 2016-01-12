<?php
namespace In2code\Powermail\Finisher;

use In2code\Powermail\Domain\Service\SaveToAnyTableService;
use In2code\Powermail\Utility\StringUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2015 Alex Kellner <alexander.kellner@in2code.de>, in2code.de
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
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
 * Save values to any table after a submit
 *
 * @package powermail
 * @license http://www.gnu.org/licenses/lgpl.html
 *          GNU Lesser General Public License, version 3 or later
 */
class SaveToAnyTableFinisher extends AbstractFinisher implements FinisherInterface
{

    /**
     * @var \TYPO3\CMS\Extbase\Service\TypoScriptService
     * @inject
     */
    protected $typoScriptService;

    /**
     * @var \In2code\Powermail\Domain\Repository\MailRepository
     * @inject
     */
    protected $mailRepository;

    /**
     * @var \TYPO3\CMS\Extbase\Object\ObjectManager
     * @inject
     */
    protected $objectManager;

    /**
     * Inject a complete new content object
     *
     * @var \TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer
     * @inject
     */
    protected $contentObject;

    /**
     * @var array
     */
    protected $dataArray = [];

    /**
     * Preperation function for every table
     *
     * @return void
     */
    public function savePreflightFinisher()
    {
        if ($this->isConfigurationAvailable()) {
            foreach (array_keys($this->configuration) as $key) {
                $this->contentObject->start($this->getDataArray());
                $tableConfiguration = $this->configuration[$key];
                $numberKey = StringUtility::removeLastDot($key);
                if ($this->isSaveToAnyTableActivatedForSpecifiedTable($tableConfiguration)) {
                    if (!$this->isDeprecatedConfiguration($numberKey)) {
                        $this->saveSpecifiedTablePreflight($numberKey, $tableConfiguration);
                    } else {
                        $this->saveSpecifiedTableDeprecatedPreflight($numberKey, $tableConfiguration);
                    }
                }
            }
        }
    }

    /**
     * Preperation function for a single table
     *
     * @param int $numberKey
     * @param array $tableConfiguration
     * @return void
     */
    protected function saveSpecifiedTablePreflight($numberKey, array $tableConfiguration)
    {
        /* @var $saveService SaveToAnyTableService */
        $saveService = $this->objectManager->get(
            SaveToAnyTableService::class,
            $this->getTableName($tableConfiguration)
        );
        $this->setModeInSaveService($saveService, $tableConfiguration);
        $this->setPropertiesInSaveService($saveService, $tableConfiguration);
        $saveService->setDevLog(!empty($this->settings['debug']['saveToTable']));
        $this->addArrayToDataArray(['uid_' . $numberKey => (int) $saveService->execute()]);
    }

    /**
     * Preperation function for a single table (from old configuration)
     *
     * @param string $table
     * @param array $tableConfiguration
     * @return void
     * @deprecated Should be removed in one of the next minor versions of powermail
     * TODO: remove complete function
     */
    protected function saveSpecifiedTableDeprecatedPreflight($table, array $tableConfiguration)
    {
        GeneralUtility::deprecationLog(
            'Tableconfiguration in plugin.tx_powermail.settings.setup.dbEntry must have ' .
            'numbers as keys. Table name as key is deprecated. See manual for a newer TypoScript example.'
        );

        /* @var $saveService SaveToAnyTableService */
        $saveService = $this->objectManager->get(SaveToAnyTableService::class, $table);
        $this->setModeInSaveService($saveService, $tableConfiguration);
        $this->setPropertiesInSaveService($saveService, $tableConfiguration);
        $saveService->setDevLog(!empty($this->settings['debug']['saveToTable']));
        $this->addArrayToDataArray(['uid_' . $table => (int) $saveService->execute()]);
    }

    /**
     * @param SaveToAnyTableService $saveService
     * @param array $tableConfiguration
     * @return void
     */
    protected function setPropertiesInSaveService(SaveToAnyTableService $saveService, array $tableConfiguration)
    {
        foreach (array_keys($tableConfiguration) as $field) {
            if (!$this->isSkippedKey($field)) {
                $value = $this->contentObject->cObjGetSingle(
                    $tableConfiguration[$field],
                    $tableConfiguration[$field . '.']
                );
                $saveService->addProperty($field, $value);
            }
        }
    }

    /**
     * Set mode and uniqueField in saveToAnyTableService
     *
     * @param SaveToAnyTableService $saveService
     * @param array $tableConfiguration
     * @return void
     */
    protected function setModeInSaveService(SaveToAnyTableService $saveService, array $tableConfiguration)
    {
        if (!empty($tableConfiguration['_ifUnique.'])) {
            $uniqueFields = array_keys($tableConfiguration['_ifUnique.']);
            $saveService->setMode($tableConfiguration['_ifUnique.'][$uniqueFields[0]]);
            $saveService->setUniqueField($uniqueFields[0]);
            if (!empty($tableConfiguration['_ifUniqueWhereClause'])) {
                $saveService->setAdditionalWhereClause($tableConfiguration['_ifUniqueWhereClause']);
            }
        }
    }

    /**
     * Read configuration from TypoScript
     *      _table = TEXT
     *      _table.value = tableName
     *
     * @param array $tableConfiguration
     * @return string
     */
    protected function getTableName(array $tableConfiguration)
    {
        return $this->contentObject->cObjGetSingle($tableConfiguration['_table'], $tableConfiguration['_table.']);
    }

    /**
     * @param array $tableConfiguration
     * @return bool
     */
    protected function isSaveToAnyTableActivatedForSpecifiedTable($tableConfiguration)
    {
        $enable = $this->contentObject->cObjGetSingle($tableConfiguration['_enable'], $tableConfiguration['_enable.']);
        return !empty($enable);
    }

    /**
     * Check if plugin.tx_powermail.settings.setup.dbEntry is not empty
     *
     * @return bool
     */
    protected function isConfigurationAvailable()
    {
        return !empty($this->configuration) && is_array($this->configuration);
    }

    /**
     * Should this key skipped because it starts with _ or ends with .
     *
     * @param string $key
     * @return bool
     */
    protected function isSkippedKey($key)
    {
        return StringUtility::startsWith($key, '_') || StringUtility::endsWith($key, '.');
    }

    /**
     * Check if the tableconfiguration is deprecated
     *
     * @param string $key
     * @return bool
     */
    protected function isDeprecatedConfiguration($key)
    {
        return !is_numeric($key);
    }

    /**
     * Add array to dataArray
     *
     * @param array $array
     * @return void
     */
    protected function addArrayToDataArray(array $array)
    {
        $dataArray = $this->getDataArray();
        $dataArray = array_merge($dataArray, $array);
        $this->setDataArray($dataArray);
    }

    /**
     * @return array
     */
    public function getDataArray()
    {
        return $this->dataArray;
    }

    /**
     * @param array $dataArray
     * @return SaveToAnyTableFinisher
     */
    public function setDataArray(array $dataArray)
    {
        $this->dataArray = $dataArray;
        return $this;
    }

    /**
     * Initialize
     */
    public function initializeFinisher()
    {
        $configuration = $this->typoScriptService->convertPlainArrayToTypoScriptArray($this->settings);
        if (!empty($configuration['dbEntry.'])) {
            $this->configuration = $configuration['dbEntry.'];
        }
        if ($this->isConfigurationAvailable()) {
            $this->addArrayToDataArray(['uid' => $this->mail->getUid()]);
            $this->addArrayToDataArray($this->mailRepository->getVariablesWithMarkersFromMail($this->mail));
        }
    }
}
