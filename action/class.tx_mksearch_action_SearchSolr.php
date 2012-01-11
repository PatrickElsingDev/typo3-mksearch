<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2011 das Medienkombinat
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

require_once(t3lib_extMgm::extPath('rn_base') . 'class.tx_rnbase.php');

tx_rnbase::load('tx_rnbase_action_BaseIOC');
tx_rnbase::load('tx_rnbase_filter_BaseFilter');
tx_rnbase::load('tx_mksearch_util_ServiceRegistry');
tx_rnbase::load('tx_mksearch_util_Misc');

/**
 * Controller to show a list of modular products
 *
 */
class tx_mksearch_action_SearchSolr extends tx_rnbase_action_BaseIOC {
	
	/**
	 * Start the dance!
	 *
	 * @param array_object $parameters
	 * @param tx_rnbase_configurations $configurations
	 * @param array_object $viewData
	 * @return string error msg or null
	 */
	function handleRequest(&$parameters,&$configurations, &$viewData){
		$confId = $this->getConfId();
		$this->handleSoftLink($parameters, $configurations, $confId);

		//Filter erstellen (z.B. Formular parsen)
		$filter = tx_rnbase_filter_BaseFilter::createFilter($parameters, $configurations, $viewData, $confId);
		
		//shall we prepare the javascript for the autocomplete feature
		$this->prepareAutocomplete($configurations, $confId.'autocomplete.');
		
		//manchmal will man nur ein Suchformular auf jeder Seite im Header einbinden
		//dieses soll dann aber nur auf eine Ergebnisseite verweisen ohne
		//selbst zu suchen
		if($configurations->get($confId.'nosearch')) return null;
		//else
		
		$fields = array();
		$options = array();
		
		// die ip muss im debug stehen
		if($parameters->get('debug')) {
			tx_rnbase::load('tx_mksearch_util_Misc');
			if(	$parameters->get('debug') == t3lib_div::getIndpEnv('REMOTE_ADDR')
				|| tx_mksearch_util_Misc::isDevIpMask()) {
				$options['debug'] = 1; $options['debugQuery'] = 'true';
			}
		}
		
		$result = null;
		if($filter->init($fields, $options)) {
			// wenn der DisMaxRequestHandler genutzt wird, verursacht *:* ein leeres Ergebnis.
			// Hier muss sich dringend der Filter darum kümmern, was im Term steht!
			if(!array_key_exists('term', $fields))
				$fields['term'] = '*:*';
			
			$indexUid = $configurations->get($confId. 'usedIndex');
			//let's see if we got a index to use via parameters
			if(empty($indexUid))
				$indexUid = $parameters->get('usedIndex');
				
			$index = tx_mksearch_util_ServiceRegistry::getIntIndexService()->get($indexUid);
			if(!$index->isValid())
				throw new Exception('Configured search index not found!');
				
			$pageBrowser = $this->handlePageBrowser($parameters, $configurations, $confId, $viewData, $fields, $options, $index);
			
			if($result = $this->searchSolr($fields, $options, $configurations, $index)) {
				// Jetzt noch die echte Listengröße im PageBrowser setzen
				if(is_object($pageBrowser)) {
					$listSize = $result['numFound'];
					$pageBrowser->setState($parameters, $listSize, $pageBrowser->getPageSize());
				}
			} else {
				$result = array('items' => array());
			}
		}
		// auch einen debug ausgeben, wenn nichts gesucht wird
		elseif ($options['debug']) {
			t3lib_div::debug(array('Filter returns false, no search done.', $fields, $options), 'class.tx_mksearch_action_SearchSolr.php Line: '.__LINE__); // TODO: remove me
		}
		$viewData->offsetSet('result', $result);
		return null;
	}
	
	/**
	 * Generiert den Cache-Schlüssel für die aktuelle Anfrage
	 *
	 * @param array $fields
	 * @param array $options
	 * @return string
	 */
	private function generateCacheKey($fields, $options) {
		// wir müssen erstmal flache array draus machen um die hash
		//Funktion nutzen zu können
		foreach (array_merge($fields, $options) as $key => $value)
			$aHashParams[] = $key.$value;
		
		return tx_rnbase_util_Misc::createHash($aHashParams);
	}
	
	/**
	 * Sucht in Solr
	 *
	 * @param array $fields
	 * @param array $options
	 * @param tx_rnbase_configurations $configurations
	 * @param tx_mksearch_model_internal_Index $index
	 *
	 * @return array|false
	 */
	private function searchSolr(&$fields, &$options, $configurations, $index) {
		// erstmal den cache fragen. Das ist vor allem interessant wenn
		// das plugin mehrfach auf einer Seite eingebunden wird. Dadurch
		// muss die Solr Suche nur einmal ausgeführt werden
		//@todo bringt das wirklich performance?
		tx_rnbase::load('tx_rnbase_cache_Manager');
		$oCache = tx_rnbase_cache_Manager::getCache('mksearch');
		$sCacheKey = $this->generateCacheKey($fields, $options);
		if($result = $oCache->get($sCacheKey))
			return $result;
		//else nix im cache also Solr suchen lassen
		
		try {
			// in unserem fall sollte es der solr service sein!
			/* @var $searchEngine tx_mksearch_service_engine_Solr */
			$searchEngine = tx_mksearch_util_ServiceRegistry::getSearchEngine($index);
			$searchEngine->openIndex($index);
			$result = $searchEngine->search($fields, $options);
			$searchEngine->closeIndex();
			
			//der solr responce processor bearbeidet die results
			// es werden hits, facets, uws. erzeugt.
			tx_rnbase::load('tx_mksearch_util_SolrResponseProcessor');
			tx_mksearch_util_SolrResponseProcessor::processSolrResult(
					$result, $configurations,
					$this->getConfId().'responseProcessor.'
				);
		}
		catch(Exception $e) {
			$lastUrl = $e instanceof tx_mksearch_service_engine_SolrException ? $e->getLastUrl() : '';
			//Da die Exception gefangen wird, würden die Entwickler keine Mail bekommen
			//also machen wir das manuell
			if($addr = tx_rnbase_configurations::getExtensionCfgValue('rn_base', 'sendEmailOnException')) {
				tx_rnbase::load('tx_rnbase_util_Misc');
				tx_rnbase_util_Misc::sendErrorMail($addr, 'tx_mksearch_action_SearchSolr_searchSolr', $e);
			}
			tx_rnbase::load('tx_rnbase_util_Logger');
			if(tx_rnbase_util_Logger::isFatalEnabled()) {
				tx_rnbase_util_Logger::fatal('Solr search failed with Exception!', 'mksearch',
					array('Exception' => $e->getMessage(), 'fields'=>$fields, 'options' => $options, 'URL'=> $lastUrl));
			}
			if($configurations->getBool($this->getConfId().'throwSolrSearchException')) {
				throw $e;
			}
			return false;
		}
		
		//alles gut gegangen. dann noch das ergebnis für den aktuellen
		//request in den cache
		$oCache->set($sCacheKey, $result);
		
		return $result;
	}

	/**
	 * Special links for configured keywords.
	 * @param tx_rnbase_IParameters $parameters
	 * @param tx_rnbase_configurations $configurations
	 *
	 */
	protected function handleSoftLink($parameters, $configurations, $confId) {
		// Softlink Config beginnt direkt im Root, damit sie auch für andere
		// Suchen genutzt werden kann
		if(!$configurations->get('softlink.enable')) return;
		$paramName = $configurations->get($confId.'softlink.parameter');
		$paramName = $paramName ? $paramName : 'term';
		$value = $parameters->get($paramName);
		$value = $value ? substr($value, 0, 150) : '';
		$options = array();
		tx_rnbase_util_SearchBase::setConfigOptions($options, $configurations, 'softlink.options.');
		$options['where'] = 'keyword=' . $GLOBALS['TYPO3_DB']->fullQuoteStr($value, 'tx_mksearch_keywords');
		$rows = tx_rnbase_util_DB::doSelect('link', 'tx_mksearch_keywords', $options);

		if(count($rows) == 1){
			$link = $configurations->createLink(false);
			$link->destination($rows[0]['link']);
			$link->redirect();
//			$redirect_url = $this->pi_linkTP_keepPIvars_url(
//				array('ux_tx_indexedsearch' => '', 'backPid' => '', 'sword' => ''),
//				$this->allowCaching, $this->conf['dontUseBackPid']?1:0,
//				$rows[0]['link']);
//			$redirect_url = strtok($redirect_url,'?');
//			header('Location: '.t3lib_div::locationHeaderUrl($redirect_url));
		}
	}

	/**
	 * PageBrowser Integration für Solr
	 * Solr liefert bei einer Suchanfrage immer die Gesamtzahl der Treffer mit.
	 * Somit ist eine spezielle Suche für die Trefferzahl nicht notwendig.
	 * @param tx_rnbase_IParameters $parameters
	 * @param tx_rnbase_configurations $configurations
	 * @return tx_rnbase_util_PageBrowser
	 */
	function handlePageBrowser($parameters,$configurations, $confId, $viewdata, &$fields, &$options, $index) {
		if(is_array($conf = $configurations->get($confId.'hit.pagebrowser.'))) {
			// PageBrowser initialisieren
			$pageBrowserId = $conf['pbid'] ? $conf['pbid'] : 'search'.$configurations->getPluginId();
			/* @var $pageBrowser tx_rnbase_util_PageBrowser */
			$pageBrowser = tx_rnbase::makeInstance('tx_rnbase_util_PageBrowser', $pageBrowserId);
			$pageSize = intval($conf['limit']);
			if($conf['limitFromRequest']) {
				$limitParam = $conf['limitFromRequest.']['param'] ? $conf['limitFromRequest.']['param'] : 'limit';
				$limitQualifier = $conf['limitFromRequest.']['qualifier'] ? $conf['limitFromRequest.']['qualifier'] : '';
				$size = $parameters->getInt($limitParam, $limitQualifier);
				$pageSize = $size ? $size : $pageSize;

				// Wir schreiben das Limit in die Parameter, wenn diese noch nicht gesetzt wurden.
				// Das wird Beispielsweise für Limit-Links benötigt,
				// welche ihren Status anhand der Parameter erkennen.
				// Dabei müssen wir die bisherigen Parameter mergen,
				// da diese sonst überschrieben werden.
				
				//@todo limit über setRegsiter setzen um im TS darauf zugreifen
				//zu können. Dann werden hier auch nicht alle Parameter von
				//anderen Plugins überschrieben
				if(!$parameters->getInt($limitParam, $limitQualifier)) {
					$params = t3lib_div::_GP($limitQualifier);
					$params = $params ? $params : array();
					$params = array_merge(array($limitParam => $pageSize),$params);
					t3lib_div::_GETset($params, $limitQualifier );
					if($limitQualifier == $parameters->getQualifier()) {
						$parameters->offsetSet($limitParam, $pageSize);
					}
				}
			}
			// Für den Pagebrowser müssen wir wissen wie viel gefunden werden wird.
			// Dafür reicht uns der Count, also setzen wir limit auf 0 zwecks performanz.
			$aOptionsForCountOnly = $options;
			$aOptionsForCountOnly['limit'] = 0;
			
			$listSize = 0;
			if($result = $this->searchSolr($fields, $aOptionsForCountOnly, $configurations, $index)) {
				$listSize = $result['numFound'];
			}
			$pageBrowser->setState($parameters, $listSize, $pageSize);
			$limit = $pageBrowser->getState();
			
			$options = array_merge($options, $limit);
			$viewdata->offsetSet('pagebrowser', $pageBrowser);
			return $pageBrowser;
		}
	}
	
	/**
	 * Include the neccessary javascripts for the autocomplete feature
	 * @param tx_rnbase_configurations $configurations
	 * @return void
	 */
	protected function prepareAutocomplete(tx_rnbase_configurations $configurations, $confId) {
		if(!$configurations->get($confId.'enable'))
			return;
			
		$aConfig = $configurations->get($confId);
		
		$sJavascriptsPath = t3lib_extMgm::siteRelPath('mksearch').'res/js/';
		$aJsScripts = array();
		if($aConfig['includeJquery'])
			$aJsScripts[] = 'jquery-1.6.2.min.js';
		if($aConfig['includeJqueryUiCore'])
			$aJsScripts[] = 'jquery-ui-1.8.15.core.min.js';
		if($aConfig['includeJqueryUiAutocomplete'])
			$aJsScripts[] = 'jquery-ui-1.8.15.autocomplete.min.js';
		
		if (!empty($aJsScripts))
			foreach ($aJsScripts as $sJavaScriptFilename)
				$GLOBALS['TSFE']->additionalHeaderData[$sJavaScriptFilename] = '<script type="text/javascript" src="'.$sJavascriptsPath.$sJavaScriptFilename.'"></script>';
				
		//now add the autocomplete call
		$oLink = $configurations->createLink();
		$oLink->initByTS(
			$configurations,
			$confId.'actionLink.',
			array(
				'usedIndex' => $configurations->get($this->getConfId().'usedIndex'),
				'ajax' => 1 //set always true
			
			)
		);
		
		$sAutocompleteJS = '
		<script type="text/javascript">
		jQuery(document).ready(function(){
			jQuery('.$aConfig['elementSelector'].').autocomplete({
				source: function( request, response ) {
					jQuery.ajax({
						url: "'.$oLink->makeUrl(false).'&mksearch[term]="+encodeURIComponent(request.term),
						dataType: "json",
						success: function( data ) {
							var suggestions = [];
							jQuery.each(data.suggestions, function(key, value) {
								jQuery.each(value, function(key, suggestion) {
									suggestions.push(suggestion.uid);
								});
							});
							response( jQuery.map( suggestions, function( item ) {
								return {
									label: item,
									value: item
								};
							}));
						}
					});
				},
				minLength: '.$aConfig['minLength'].'
			});
		});
		jQuery(".ui-autocomplete.ui-menu.ui-widget.ui-widget-content.ui-corner-all").show();
		</script>
		';
		
		$GLOBALS['TSFE']->additionalHeaderData[md5($sAutocompleteJS)] = $sAutocompleteJS;
	}

	function getTemplateName() { return 'searchsolr';}
	function getViewClassName() { return 'tx_mksearch_view_SearchSolr';}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/action/class.tx_mksearch_action_SearchSolr.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/action/class.tx_mksearch_action_SearchSolr.php']);
}
