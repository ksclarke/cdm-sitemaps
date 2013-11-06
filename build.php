<?php

/*
 * This is a PHP script that will generate sitemaps for each collection in a
 * ContentDM instance.  It also creates a sitemap index that references each
 * of the individual publicly available collections.  See the project's README
 * file for more information.
 * 
 * License: Copyright 2011 UC Regents. Open source BSD license.
 * Author: Kevin S. Clarke <ksclarke@gmail.com>
 * 
 */

// Show me the errors!!
error_reporting(E_ALL);
ini_set('display_errors', '1');


//YOU NEED TO SET THIS TO YOUR LOCAL PATH TO ZEND
$zendpath = '/usr/local/contentdm6/Website/cdm_common/library';
set_include_path(get_include_path() . PATH_SEPARATOR . $zendpath);

require_once 'Zend/Http/Client.php';
require_once 'Zend/Config/Xml.php';
require_once 'Zend/Log/Writer/Stream.php';
require_once 'Zend/Log.php';
require_once 'Zend/Registry.php';

function createSitemapIndex($publicxml, $cdmxml, $http, $service, $website, $path) {
	$xml = new DOMDocument('1.0');
	$doc = new DOMDocument('1.0');
	$public = new DOMDocument('1.0');

	$ns = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	$index = $doc->appendChild($doc->createElementNS($ns, 'sitemapindex'));

	$xml->loadXML($cdmxml);
	$public->loadXML($publicxml);
	$publicXPath = new DOMXPath($public);
	$cdmXPath = new DOMXPath($xml);

	//Zend_Registry::get('logger')->log($xml->saveXML(), Zend_Log::DEBUG);

	$collections = $cdmXPath->query("/collections/collection");
	$published = strcmp($publicxml, $cdmxml) == 0;

	foreach ($collections as $collection) {
		$alias = $collection->getElementsByTagName('alias')->item(0)->nodeValue;
		$name = $collection->getElementsByTagName('name')->item(0)->nodeValue;

		// Turn the collection alias into a file name
		$fileName = 'sitemap-' . substr($alias, 1) . '.xml';

		if ($published || contains($publicXPath, $name)) {
			$sitemap = $doc->createElementNS($ns, 'sitemap');
			$index->appendChild($sitemap);
			$loc = $doc->createElementNS($ns, 'loc', $website . '/' . $path . '/' . $fileName);
			$sitemap->appendChild($loc);

			$lastModDate = createSitemap($alias, $name, $http, $service);
			$lastModElem = $doc->createElementNS($ns, 'lastmod', $lastModDate);
			$lastmod = $sitemap->appendChild($lastModElem);
		}
		else {
			createSitemap($alias, $name, $http, $service);
		}
	}

	$doc->formatOutput = true;
	echo $doc->saveXML();
	$doc->save(dirname( __FILE__ ) . '/sitemap.xml');
}

function createSitemap($alias, $name, $http, $service) {
	$xml = new DOMDocument('1.0');
	$sitemap = new DOMDocument('1.0');
	$ns = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	$urlset = $sitemap->appendChild($sitemap->createElementNS($ns, 'urlset'));
	$lastModDate = strtotime('1970-06-01'); // start date for comparisons
	$host = $http->getUri(false)->getHost();
	$total = -1;
	$start = 1;
	
	while ($total > 0 || $total == -1) {
		if ($total == -1) {
			Zend_Registry::get('logger')->log('Starting sitemap for ' . $name . ' (' . $alias . ') ', Zend_Log::DEBUG);
		}
		
		$query = buildQuery($service, $alias, strval($start));
		$result = processRequest($http, $query);
		$xml->loadXML($result);
		$xpath = new DOMXPath($xml);
		
		if ($total == -1) {
			$nodes = $xpath->query("/results/pager/total");
			$total = intval($nodes->item(0)->nodeValue);
			
			Zend_Registry::get('logger')->log('Total records: ' . $total, Zend_Log::DEBUG);
			Zend_Registry::get('logger')->log('Query: ' . $query, Zend_Log::DEBUG);
		}
		
		$records = $xpath->query("/results/records/record");
	
		foreach ($records as $record) {
			$modNodes = $record->getElementsByTagName('dmmodified');
			$mod = $modNodes->item(0)->nodeValue;
			$idNodes = $record->getElementsByTagName('dmrecord');
			$id = $idNodes->item(0)->nodeValue;
			$thisLastModDate = strtotime($mod);

			if ($thisLastModDate > $lastModDate) {
				$lastModDate = $thisLastModDate;
			}

			// use v.5 URLs b/c v.6 requires you know if the item is compound
			$loc = 'http://' . $host . '/u?' . $alias . ',' . $id;
			
			//Zend_Registry::get('logger')->log($loc, Zend_Log::DEBUG);
			
			$url = $urlset->appendChild($sitemap->createElementNS($ns, 'url'));
			$url->appendChild($sitemap->createElementNS($ns, 'loc', $loc));
			$url->appendChild($sitemap->createElementNS($ns, 'lastmod', $mod));
		
			if ($total < 500) {
				Zend_Registry::get('logger')->log($total . ' id: ' . $id, Zend_Log::DEBUG);
				$total -= 1;
			}
		}
		
		// tweak our loop parameters
		if ($total > 500 || $total == -1) {
			Zend_Registry::get('logger')->log($total . ' ' . $start, Zend_Log::DEBUG);

			$start = $start + 500;
			$total = $total - 500;
		}
	}

	$fileName = dirname( __FILE__ ) . '/sitemap-' . substr($alias, 1) . '.xml';
	$sitemap->formatOutput = true;
	$sitemap->save($fileName);
	
	// include a little human-friendly hint as to the collection name
	$file = fopen($fileName, 'a');
	fwrite($file, '<!-- ' . $name . ' -->');
	fclose($file);
	
	//Zend_Registry::get('logger')->log($sitemap->saveXML(), Zend_Log::DEBUG);
	
	return date('Y-m-d', $lastModDate);
}

function buildQuery($service, $alias, $index) {
	$query = $service . 'q=dmQuery' . $alias;
	$query = $query . '/0/dmmodified!dmrecord/dmmodified/500/';
	return $query . $index . '/0/0/0/0/xml';
}

function contains($xpath, $name) {
	$nodes = $xpath->query("/collections/collection/name[.='" . $name . "']");
	return $nodes->length > 0;
}

function processRequest($http, $url) {
	$http->setUri($url);
	$response = $http->request();

	if ($response->isSuccessful()) {
		return $response->getBody();
	}
	else {
		throw new Zend_Http_Client_Exception('Unable to read response');
	}
}

$config = new Zend_Config_Xml('config.xml', 'production');
$website = 'http://' . $config->website;
$host = 'http://' . $config->server;
$service = $host . '/dmwebservices/index.php?';
$query = 'q=dmGetCollectionList/xml';

$writer = new Zend_Log_Writer_Stream($config->logfile);
Zend_Registry::set('logger', new Zend_Log($writer));

try {
	$http = new Zend_Http_Client();
	//$http->setConfig(array('keepalive' => true));
	$public = processRequest($http, $service . $query);

	if ($config->unpublished == 'true') {
		$http->setCookieJar();
		$http->setAuth($config->user, $config->password);
		$http->setUri($host . '/cgi-bin/admin/start.exe');
		$response = $http->request();

		if ($response->isSuccessful()) {
			$body = processRequest($http, $service . $query);
			createSitemapIndex($public, $body, $http, $service, $website, $config->path);
		}
		else {
			throw new Zend_Http_Client_Exception('Unable to read response');
		}
	}
	else {
		$body = processRequest($http, $service . $query);
		createSitemapIndex($public, $body, $http, $service, $website, $config->path);
	}
}
catch (Zend_Http_Client_Exception $details) {
	echo '<p>An error occurred (' . $details->getMessage() . ')</p>';
}

?>

<!-- Generated by cdm-sitemaps - https://github.com/ksclarke/cdm-sitemaps -->
