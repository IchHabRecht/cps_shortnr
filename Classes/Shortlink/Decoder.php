<?php
namespace CPSIT\CpsShortnr\Shortlink;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2017 Nicole Cordes <cordes@cps-it.de>
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

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\TypoScript\Parser\TypoScriptParser;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Configuration\TypoScript\ConditionMatching\ConditionMatcher;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class Decoder
{
    /**
     * @var array
     */
    private $configuration;

    /**
     * @var string
     */
    private $decodeIdentifier;

    /**
     * @var string
     */
    private $pattern;

    /**
     * @var array
     */
    private $recordInformation;

    /**
     * @var string
     */
    private $shortlink;

    /**
     * @param array $configuration
     * @param string $shortlink
     * @param string $pattern
     */
    public function __construct(array $configuration, $shortlink, $pattern)
    {
        $this->configuration = $configuration;
        $this->pattern = $pattern;
        $this->shortlink = $shortlink;
    }

    /**
     * @param string $configurationFile
     * @param string $shortlink
     * @param string $pattern
     * @throws \RuntimeException
     * @return Decoder
     */
    public static function createFromConfigurationFile($configurationFile, $shortlink, $pattern)
    {
        $file = GeneralUtility::getUrl($configurationFile);
        if (empty($file)) {
            throw new \RuntimeException('Configuration file could not be read', 1490608852);
        }
            /** @var TypoScriptParser $typoScriptParser */
            $typoScriptParser = GeneralUtility::makeInstance(TypoScriptParser::class);
        $conditionMatcher = GeneralUtility::makeInstance(ConditionMatcher::class);
        $typoScriptParser->parse($file, $conditionMatcher);

        $typoScriptArray = $typoScriptParser->setup;

        if (!isset($typoScriptArray['cps_shortnr.'])) {
            throw new \RuntimeException('No "cps_shortnr" configuration found', 1490608923);
        }

        return new Decoder($typoScriptArray['cps_shortnr.'], $shortlink, $pattern);
    }

    /**
     * @return array
     */
    public function getShortlinkParts()
    {
        $regularExpression = $this->pattern;
        $regularExpression = str_replace('/', '\\/', $regularExpression);

        $matches = [];
        preg_match('/' . $regularExpression . '/', $this->shortlink, $matches);

        return $matches;
    }

    /**
     * @throws \RuntimeException
     * @return array
     */
    public function getRecordInformation()
    {
        if ($this->decodeIdentifier === null) {
            $this->resolveDecodeIdentifier();
        }

        if (empty($this->configuration[$this->decodeIdentifier . '.'])) {
            throw new \RuntimeException('Missing shortlink configuration for key "' . $this->decodeIdentifier . '"', 1490608891);
        }

        $shortLinkConfiguration = $this->configuration[$this->decodeIdentifier . '.'];

        if (empty($shortLinkConfiguration['source.'])
            || (empty($shortLinkConfiguration['source.']['record']) && empty($shortLinkConfiguration['source.']['record.']))
            || empty($shortLinkConfiguration['source.']['table'])
            || empty($shortLinkConfiguration['path.'])
        ) {
            throw new \RuntimeException('Invalid shortlink configuration', 1490608898);
        }

        // Get record
        if (empty($shortLinkConfiguration['source.']['record.'])) {
            $recordUid = (int)$shortLinkConfiguration['source.']['record'];
        } else {
            $contentObjectRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);
            $recordUid = (int)$contentObjectRenderer->stdWrap(
                isset($shortLinkConfiguration['source.']['record']) ? $shortLinkConfiguration['source.']['record'] : '',
                $shortLinkConfiguration['source.']['record.']
            );
        }

        $table = $shortLinkConfiguration['source.']['table'];
        $record = BackendUtility::getRecord($table, $recordUid);

        if ($record === null) {
            throw new \RuntimeException('No record for "' . $recordUid . '" found', 1490609023);
        }

        $this->recordInformation = [
            'record' => $record,
            'table' => $table,
        ];

        return $this->recordInformation;
    }

    /**
     * @return string
     */
    public function getPath()
    {
        if ($this->recordInformation === null) {
            $this->getRecordInformation();
        }

        $contentObjectRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $contentObjectRenderer->start($this->recordInformation['record'], $this->recordInformation['table']);

        return $contentObjectRenderer->stdWrap('', $this->configuration[$this->decodeIdentifier . '.']['path.']);
    }

    /**
     * @return void
     */
    private function resolveDecodeIdentifier()
    {
        // Get decode information and configuration
        if (empty($this->configuration['decoder']) && empty($this->configuration['decoder.'])) {
            throw new \RuntimeException('Missing key configuration', 1490608877);
        }

        $contentObjectRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);

        if (empty($this->configuration['decoder.'])) {
            $this->decodeIdentifier = strtolower($this->configuration['decoder']);
        } else {
            $this->decodeIdentifier = strtolower($contentObjectRenderer->stdWrap(
                isset($this->configuration['decoder']) ? $this->configuration['decoder'] : '',
                $this->configuration['decoder.']
            ));
        }
    }
}
