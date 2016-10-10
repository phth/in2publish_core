<?php
namespace In2code\In2publishCore\Domain\Factory;

/***************************************************************
 * Copyright notice
 *
 * (c) 2016 in2code.de and the following authors:
 * Oliver Eglseder <oliver.eglseder@in2code.de>
 *
 * All rights reserved
 *
 * This script is part of the TYPO3 project. The TYPO3 project is
 * free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 *
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use In2code\In2publishCore\Domain\Model\RecordInterface;
use In2code\In2publishCore\Service\Database\UidReservationService;
use In2code\In2publishCore\Utility\ConfigurationUtility;
use In2code\In2publishCore\Utility\DatabaseUtility;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Class FileIndexFactory
 */
class FileIndexFactory
{
    /**
     * @var DriverInterface
     */
    protected $localDriver = null;

    /**
     * @var DriverInterface
     */
    protected $foreignDriver = null;

    /**
     * @var UidReservationService
     */
    protected $uidReservationService = null;

    /**
     * @var array
     */
    protected $sysFileTca = array();

    /**
     * @var bool
     */
    protected $persistTemporaryIndexing = false;

    /**
     * FileIndexFactory constructor.
     * @param DriverInterface $localDriver
     * @param DriverInterface $foreignDriver
     */
    public function __construct(DriverInterface $localDriver, DriverInterface $foreignDriver)
    {
        $this->localDriver = $localDriver;
        $this->foreignDriver = $foreignDriver;
        $this->uidReservationService = GeneralUtility::makeInstance(
            'In2code\\In2publishCore\\Service\\Database\\UidReservationService'
        );
        $this->sysFileTca = GeneralUtility::makeInstance('In2code\\In2publishCore\\Service\\Configuration\\TcaService')
                                          ->getConfigurationArrayForTable('sys_file');
        $this->persistTemporaryIndexing = (bool)ConfigurationUtility::getConfiguration(
            'factory.fal.persistTemporaryIndexing'
        );
    }

    /**
     * @param array $localProperties
     * @param array $foreignProperties
     * @return RecordInterface
     */
    public function makeInstance(array $localProperties, array $foreignProperties)
    {
        return GeneralUtility::makeInstance(
            'In2code\\In2publishCore\\Domain\\Model\\Record',
            'sys_file',
            $localProperties,
            $foreignProperties,
            $this->sysFileTca,
            array('localRecordExistsTemporary' => true, 'foreignRecordExistsTemporary' => true)
        );
    }

    /**
     * @param string $side
     * @param array $properties
     * @return RecordInterface
     */
    public function makeInstanceForSide($side, array $properties)
    {
        return GeneralUtility::makeInstance(
            'In2code\\In2publishCore\\Domain\\Model\\Record',
            'sys_file',
            $side === 'local' ? $properties : array(),
            $side === 'foreign' ? $properties : array(),
            $this->sysFileTca,
            array($side . 'RecordExistsTemporary' => true)
        );
    }

    /**
     * This method is mostly a copy of an indexer method
     * @see \TYPO3\CMS\Core\Resource\Index\Indexer::gatherFileInformationArray
     *
     * @param string $identifier
     * @param $side
     * @param int $uid Predefined UID
     * @return array
     */
    public function getFileIndexArray($identifier, $side, $uid = 0)
    {
        $fileInfo = $this->getDriverSpecificFileInfo($identifier, $side);

        $remapKeys = array(
            'mtime' => 'modification_date',
            'ctime' => 'creation_date',
            'mimetype' => 'mime_type',
        );

        foreach ($remapKeys as $fileInfoKey => $sysFileRecordKey) {
            $fileInfo[$sysFileRecordKey] = $fileInfo[$fileInfoKey];
            unset($fileInfo[$fileInfoKey]);
        }

        $fileInfo['type'] = $this->determineFileType($fileInfo);
        $fileInfo['extension'] = PathUtility::pathinfo($fileInfo['name'], PATHINFO_EXTENSION);
        $fileInfo['missing'] = 0;
        $fileInfo['last_indexed'] = 0;
        $fileInfo['metadata'] = 0;
        $fileInfo['tstamp'] = time();
        $fileInfo['pid'] = 0;

        if ($uid > 0) {
            $fileInfo['uid'] = $uid;
        } else {
            $fileInfo['uid'] = $this->uidReservationService->getReservedUid();
        }

        // convert all values to string to match the resulting types of a database select query result
        foreach ($fileInfo as $index => $value) {
            $fileInfo[$index] = (string)$value;
        }

        if (true === $this->persistTemporaryIndexing) {
            $this->persistFileInfo($fileInfo, $side);
        }

        return $fileInfo;
    }

    /**
     * Adapted copy of
     * @see \TYPO3\CMS\Core\Resource\Index\Indexer::getFileType
     *
     * @param array $fileInfo
     * @return int
     */
    protected function determineFileType(array $fileInfo)
    {
        list($fileType) = explode('/', $fileInfo['mime_type']);
        switch (strtolower($fileType)) {
            case 'text':
                $type = File::FILETYPE_TEXT;
                break;
            case 'image':
                $type = File::FILETYPE_IMAGE;
                break;
            case 'audio':
                $type = File::FILETYPE_AUDIO;
                break;
            case 'video':
                $type = File::FILETYPE_VIDEO;
                break;
            case 'application':
            case 'software':
                $type = File::FILETYPE_APPLICATION;
                break;
            default:
                $type = File::FILETYPE_UNKNOWN;
        }
        return $type;
    }

    /**
     * @param string $identifier
     * @param string $side
     * @return array
     */
    protected function getDriverSpecificFileInfo($identifier, $side)
    {
        if ($side === 'local') {
            $driver = $this->localDriver;
        } elseif ($side === 'foreign') {
            $driver = $this->foreignDriver;
        } else {
            throw new \LogicException('Unsupported side "' . $side . '"', 1476106674);
        }

        $fileInfo = $driver->getFileInfoByIdentifier($identifier);
        unset($fileInfo['atime']);
        $fileInfo['sha1'] = $driver->hash($identifier, 'sha1');
        return $fileInfo;
    }

    /**
     * @param array $fileInfo
     * @param string $side
     */
    protected function persistFileInfo(array $fileInfo, $side)
    {
        $fileInfo = array_intersect_key(
            $fileInfo,
            array(
                'uid' => '',
                'pid' => '',
                'missing' => '',
                'type' => '',
                'storage' => '',
                'identifier' => '',
                'identifier_hash' => '',
                'extension' => '',
                'mime_type' => '',
                'name' => '',
                'sha1' => '',
                'size' => '',
                'creation_date' => '',
                'modification_date' => '',
                'folder_hash' => '',
                'tstamp' => '',
            )
        );

        if ($side === 'local') {
            $database = DatabaseUtility::buildLocalDatabaseConnection();
        } elseif ($side === 'foreign') {
            $database = DatabaseUtility::buildForeignDatabaseConnection();
        } else {
            throw new \LogicException('Unsupported side "' . $side . '"', 1476108347);
        }

        $database->exec_INSERTquery('sys_file', $fileInfo);
    }
}
