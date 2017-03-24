<?php
namespace CPSIT\CpsShortnr\Controller;

/***************************************************************
 *  Copyright notice
 *  (c) 2012 Nicole Cordes <cordes@cps-it.de>
 *  All rights reserved
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Backend\Configuration\TypoScript\ConditionMatching\ConditionMatcher;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\TypoScript\Parser\TypoScriptParser;
use TYPO3\CMS\Core\TypoScript\TemplateService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Page\PageRepository;

/**
 * Evaluates if the given url is a short link and redirects to parent page
 *
 * @author Nicole Cordes <cordes@cps-it.de>
 */
class PageNotFoundController implements SingletonInterface
{
    /**
     * @var array
     */
    public $configuration = [];

    /**
     * @var array
     */
    public $params = [];

    /**
     * @var TypoScriptFrontendController|null
     */
    public $tempTSFE = null;

    /**
     * @var array
     */
    public $typoScriptArray = [];

    public function __construct()
    {
        $this->init();
    }

    /**
     * @param array $params
     * @return void
     */
    public function resolvePath($params)
    {
        $this->params = $params;

        // If no config file was defined return to original pageNotFound_handling
        if (substr($this->configuration['configFile'], 0, 5) !== 'FILE:') {
            $configurationFile = PATH_site . $this->configuration['configFile'];
        } else {
            $configurationFile = GeneralUtility::getFileAbsFileName(substr($this->configuration['configFile'], 5));
        }
        if (!file_exists($configurationFile)) {
            $this->executePageNotFoundHandling('Configuration file not found');
        }

        // Convert file content to TypoScript array
        $this->getTypoScriptArray($configurationFile);

        // Initialize new TSFE object
        $this->initTSFE();

        // Write register
        array_push($GLOBALS['TSFE']->registerStack, $GLOBALS['TSFE']->register);
        $this->writeRegisterMatches();

        $shortLinkConfiguration = $this->getShortLinkConfiguration();

        $path = $contentObject->cObjGetSingle($this->typoScriptArray['cps_shortnr'], $this->typoScriptArray['cps_shortnr.']);

        $this->shutdown($path);
    }

    /**
     * @param string $content
     * @param array $configuration
     * @return int
     */
    public function checkPidInRootline($content, $configuration)
    {
        $content = (int)$content;
        if (empty($configuration['table']) || empty($configuration['table.'])) {
            $table = 'pages';
        } else {
            $contentObjectRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);
            $table = $contentObjectRenderer->cObjGetSingle($configuration['table'], $configuration['table.']);
        }

        if (empty($table) || $table === 'pages') {
            $pid = $content;
        } else {
            $record = BackendUtility::getRecord($table, $content, 'pid');
            if (empty($record)) {
                $this->executePageNotFoundHandling('No record found');
            }
            $pid = $record['pid'];
        }
        $GLOBALS['TSFE']->id = $pid;
        $GLOBALS['TSFE']->domainStartPage = $GLOBALS['TSFE']->findDomainRecord($GLOBALS['TSFE']->TYPO3_CONF_VARS['SYS']['recursiveDomainSearch']);
        $GLOBALS['TSFE']->getPageAndRootlineWithDomain($GLOBALS['TSFE']->domainStartPage);
        if (!empty($GLOBALS['TSFE']->pageNotFound)) {
            $this->executePageNotFoundHandling('ID was outside the domain');
        }

        return $content;
    }

    /**
     * @return void
     */
    protected function init()
    {
        $this->configuration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['cps_shortnr']);
    }

    /**
     * @param string $reason
     * @return void
     */
    protected function executePageNotFoundHandling($reason = '')
    {
        $reason = $reason ?: $this->params['reasonText'];
        $GLOBALS['TSFE']->pageNotFoundHandler($this->configuration['pageNotFound_handling'], '', $reason);
        exit;
    }

    /**
     * @return array
     */
    protected function getShortLinkConfiguration()
    {
        // Get key and configuration
        if (empty($this->typoScriptArray['key'])
            && empty($this->typoScriptArray['key.'])
        ) {
            $this->executePageNotFoundHandling('Missing key configuration');
        }

        $contentObject = GeneralUtility::makeInstance(ContentObjectRenderer::class);

        if (empty($this->typoScriptArray['key.'])) {
            $key = strtolower($this->typoScriptArray['key']);
        } else {
            $key = strtolower($contentObject->stdWrap(
                isset($this->typoScriptArray['key']) ? $this->typoScriptArray['key'] : '',
                $this->typoScriptArray['key.']
            ));
        }

        if (empty($this->typoScriptArray[$key . '.'])) {
            $this->executePageNotFoundHandling('Missing shortlink configuration for key "' . $key . '"');
        }

        return $this->typoScriptArray[$key . '.'];
    }

    /**
     * @param string $configurationFile
     * @return void
     */
    protected function getTypoScriptArray($configurationFile)
    {
        $file = GeneralUtility::getUrl($configurationFile);
        if (empty($file)) {
            $this->executePageNotFoundHandling('Configuration file could not be read');
        } else {
            /** @var TypoScriptParser $typoScriptParser */
            $typoScriptParser = GeneralUtility::makeInstance(TypoScriptParser::class);
            $conditionMatcher = GeneralUtility::makeInstance(ConditionMatcher::class);
            $typoScriptParser->parse($file, $conditionMatcher);

            $typoScriptArray = $typoScriptParser->setup;

            if (!isset($typoScriptArray['cps_shortnr.'])) {
                $this->executePageNotFoundHandling('No "cps_shortnr" configuration found');
            }

            $this->typoScriptArray = $typoScriptArray['cps_shortnr.'];
        }
    }

    /**
     * @return void
     */
    protected function initTSFE()
    {
        $this->tempTSFE = $GLOBALS['TSFE'];

        // Only open urls for the current domain
        $GLOBALS['TSFE']->config['mainScript'] = 'index.php';
        $GLOBALS['TSFE']->config['config']['typolinkEnableLinksAcrossDomains'] = 0;

        // Enable realurl
        $GLOBALS['TSFE']->config['config']['tx_realurl_enable'] = 1;

        // Initialize the page select object
        $GLOBALS['TSFE']->sys_page = GeneralUtility::makeInstance(PageRepository::class);
        $GLOBALS['TSFE']->sys_page->versioningPreview = false;
        $GLOBALS['TSFE']->sys_page->versioningWorkspaceId = false;
        $GLOBALS['TSFE']->sys_page->init(false);

        // Initialize the template object
        $GLOBALS['TSFE']->tmpl = GeneralUtility::makeInstance(TemplateService::class);
        $GLOBALS['TSFE']->tmpl->init();
        $GLOBALS['TSFE']->tmpl->tt_track = 0;
    }

    /**
     * @return void
     */
    protected function writeRegisterMatches()
    {
        $regularExpression = $this->configuration['regExp'];
        $regularExpression = str_replace('/', '\\/', $regularExpression);

        preg_match('/' . $regularExpression . '/', $this->params['currentUrl'], $matches);
        if (count($matches)) {
            foreach ($matches as $key => $value) {
                $GLOBALS['TSFE']->register['tx_cpsshortnr_match_' . $key] = $value;
            }
        }
    }

    /**
     * @param string $path
     */
    protected function shutdown($path)
    {
        // Restore TSFE
        $GLOBALS['TSFE'] = $this->tempTSFE;

        // Check for redirection
        if (!empty($path)) {
            $GLOBALS['TSFE']->hook_eofe();
            header('HTTP/1.0 301 TYPO3 cps_shortnr redirect');
            header('Location: ' . GeneralUtility::locationHeaderUrl($path));
            exit;
        }
        $this->executePageNotFoundHandling();
    }
}
