#!/usr/bin/env php
<?php

include_once( 'kernel/classes/ezscript.php' );
include_once( 'lib/ezutils/classes/ezcli.php' );
include_once( 'kernel/classes/ezsearch.php' );

$cli =& eZCLI::instance();

$scriptSettings = array();
$scriptSettings['description'] = 'Sends an optimize update message to the Solr search server';
$scriptSettings['use-session'] = true;
$scriptSettings['use-modules'] = true;
$scriptSettings['use-extensions'] = true;

$script =& eZScript::instance( $scriptSettings );
$script->startup();

$config = '';
$argumentConfig = '';
$optionHelp = false;
$arguments = false;
$useStandardOptions = true;

$options = $script->getOptions( $config, $argumentConfig, $optionHelp, $arguments, $useStandardOptions );
$script->initialize();

$searchEngine = eZSearch::getEngine();

if ( strtolower( get_class( $searchEngine ) ) != 'ezsolr' )
{
    $script->shutdown( 1, 'The current search engine plugin is not eZSolr' );
}

$searchEngine->optimize();

$script->shutdown( 0 );

?>