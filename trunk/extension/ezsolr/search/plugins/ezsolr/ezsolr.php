<?php
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: Solr search plugin for eZ publish
// SOFTWARE RELEASE: 0.x
// COPYRIGHT NOTICE: Copyright (C) 2007 Kristof Coomans <kristof[dot]coomans[at]telenet[dot]be>
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
//
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//

class eZSolr
{
    var $SolrINI;
    var $SearchSeverURI;

    /*!
     \brief Constructor
    */
    function eZSolr()
    {
        $this->SolrINI =& eZINI::instance( 'solr.ini' );
        $this->SearchServerURI = $this->SolrINI->variable( 'SolrSettings', 'SearchServerURI' );
    }

    /*!
     Returns a list of meta attributes to post to the search server
    */
    function metaAttributes()
    {
        $metaAttributes = array(
            'id',
            'name',
            'class_name',
            'section_id',
            'owner_id',
            'contentclass_id',
            'current_version',
            'remote_id',
            'class_identifier',
            'main_node_id',
            /*'modified',
            'published',*/
            'main_parent_node_id'
        );
        return $metaAttributes;
    }

    /*!
     \brief Adds a content object to the Solr search server
    */
    function addObject( &$contentObject, $uri )
    {
        $fields = array();
        //eZDebug::writeDebug( $contentObject->attribute( 'id' ), 'eZSolr::addObject object id' );

        include_once( 'lib/ezxml/classes/ezxml.php' );
        $dom = new eZDOMDocument();
        $add = $dom->createElement( 'add' );
        $dom->setRoot( $add );

        $doc = $dom->createElement( 'doc' );
        $add->appendChild( $doc );

        $metaAttributes = eZSolr::metaAttributes();

        foreach ( $metaAttributes as $metaAttribute )
        {
            unset( $field );
            unset( $fieldValue );

            $field = $dom->createElement( 'field' );
            //add a prefix to avoid Analyzer issues that throw away our fields like 'name'
            $fieldName = 'm_' . $metaAttribute;
            $field->setAttribute( 'name', $fieldName );
            $fieldValue = eZDOMDocument::createTextNode( $contentObject->attribute( $metaAttribute ) );
            $field->appendChild( $fieldValue );
            $doc->appendChild( $field );
        }

        $mainNode =& $contentObject->attribute( 'main_node' );
        $pathArray = $mainNode->attribute( 'path_array' );
        eZDebug::writeDebug( $pathArray );

        foreach ( $pathArray as $pathNodeID )
        {
            unset( $field );
            unset( $fieldValue );

            $field = $dom->createElement( 'field' );
            $field->setAttribute( 'name', 'm_path' );
            $fieldValue = eZDOMDocument::createTextNode( $pathNodeID );
            $field->appendChild( $fieldValue );
            $doc->appendChild( $field );
        }

        $updateURI = $this->SearchServerURI . '/update';
        $this->post( $updateURI, $dom->toString() );
        $this->post( $updateURI, '<commit/>' );

        // we should add a custom module and/or script for this, placing it here is inefficient and will slow down the updatesearchindex.php script a lot
        // maybe do the same for the Lucene plugin (we have a hacked script now as far as I can remember)
        //$this->post( $updateURI, '<optimize/>' );
    }

    /*!
     \return a Lucene query string which can be used as filter query for Solr
     \todo Handle "group" value of Owner limitation
     \todo Investigate if we can group multiple clauses to a single field: http://lucene.apache.org/java/docs/queryparsersyntax.html#Field%20Grouping
    */
    function policyLimitationFilterQuery()
    {
        include_once( 'kernel/classes/datatypes/ezuser/ezuser.php' );
        $currentUser =& eZUser::currentUser();
        $accessResult = $currentUser->hasAccessTo( 'content', 'read' );

        if ( in_array( $accessResult['accessWord'], array( 'yes', 'no' ) ) )
        {
            return false;
        }

        $policies =& $accessResult['policies'];

        $limitationHash = array(
           'Class'        => 'm_contentclass_id',
           'Section'      => 'm_section_id',
           'User_Section' => 'm_section_id',
           'Subtree'      => 'm_path_string',
           'User_Subtree' => 'm_path_string',
           'Node'         => 'm_main_node_id',
           'Owner'        => 'm_owner_id' );

        $filterQueryPolicies = array();

        // policies are concatenated with OR
        foreach ( $policies as $limitationList )
        {
            // policy limitations are concatenated with AND
            $filterQueryPolicyLimitations = array();

            foreach ( $limitationList as $limitationType => $limitationValues )
            {
                // limitation values of one type in a policy are concatenated with OR
                $filterQueryPolicyLimitationParts = array();

                switch ( $limitationType )
                {
                    case 'User_Subtree':
                    case 'Subtree':
                        {
                            foreach ( $limitationValues as $limitationValue )
                            {
                                $pathString = trim( $limitationValue, '/' );
                                $pathArray = explode( '/', $pathString );
                                // we only take the last node ID in the path identification string
                                $subtreeNodeID = array_shift( $pathArray );
                                $filterQueryPolicyLimitationParts[] = 'm_path:' . $subtreeNodeID;
                            }
                        } break;

                    case 'Node':
                        {
                            foreach ( $limitationValues as $limitationValue )
                            {
                                $pathString = trim( $limitationValue, '/' );
                                $pathArray = explode( '/', $pathString );
                                // we only take the last node ID in the path identification string
                                $nodeID = array_shift( $pathArray );
                                $filterQueryPolicyLimitationParts[] = 'm_main_node_id:' . $nodeID;
                            }
                        } break;


                    case 'Owner':
                        {
                            $filterQueryPolicyLimitationParts[] = 'm_owner_id:' . $currentUser->attribute ( 'contentobject_id' );
                        } break;

                    case 'Class':
                    case 'Section':
                    case 'User_Section':
                        {
                            foreach ( $limitationValues as $limitationValue )
                            {
                                $filterQueryPolicyLimitationParts[] = $limitationHash[$limitationType] . ':' . $limitationValue;
                            }
                        } break;

                    default :
                        {
                            eZDebug::writeDebug( $limitationType, 'eZSolr::policyLimitationFilterQuery unknown limitation type: ' . $limitationType );
                            continue;
                        }
                }

                $filterQueryPolicyLimitations[] = '( ' . implode( ' OR ', $filterQueryPolicyLimitationParts ) . ' )';
            }

            if ( count( $filterQueryPolicyLimitations ) > 0 )
            {
                $filterQueryPolicies[] = '( ' . implode( ' AND ', $filterQueryPolicyLimitations ) . ')';
            }
        }

        if ( count( $filterQueryPolicies ) > 0 )
        {
            $filterQuery = implode( ' OR ', $filterQueryPolicies );
        }
        else
        {
            return false;
        }
        eZDebug::writeDebug( $filterQuery, 'eZSolr::policyLimitationFilterQuery' );

        return $filterQuery;
    }


    function optimize()
    {
        $updateURI = $this->SearchServerURI . '/update';
        $this->post( $updateURI, '<optimize/>' );
    }

    /*!
     \brief Removes an object from the Solr search server
    */
    function removeObject( $contentObject )
    {
        $updateURI = $this->SearchServerURI . '/update';
        $this->post( $updateURI, '<delete><id>' . $contentObject->attribute( 'id' ) . '</id></delete>' );
        return true;
    }

    /*!
     \brief Search on the Solr search server
     \todo see if we can use eZHTTPTool::sendHTTPRequest instead
    */
    function search( $searchText, $params = array(), $searchTypes = array() )
    {
        $searchCount = 0;

        $offset = ( isset( $params['SearchOffset'] ) && $params['SearchOffset'] ) ? $params['SearchOffset'] : 0;
        $limit = ( isset( $params['SearchLimit']  ) && $params['SearchLimit'] ) ? $params['SearchLimit'] : 20;
        $subtrees = isset( $params['SearchSubTreeArray'] ) ? $params['SearchSubTreeArray'] : array();

        $filterQuery = array();

        if ( count( $subtrees ) > 0 )
        {
            $subtreeQueryParts = array();
            foreach ( $subtrees as $subtreeNodeID )
            {
                $subtreeQueryParts[] = 'm_path:' . $subtreeNodeID;
            }

            $filterQuery[] = implode( ' OR ', $subtreeQueryParts );
        }

        $policyLimitationFilterQuery = $this->policyLimitationFilterQuery();

        if ( $policyLimitationFilterQuery !== false )
        {
            $filterQuery[] = $policyLimitationFilterQuery;
        }

        $queryParams = array(
            'start' => $offset,
            'rows' => $limit,
            'indent' => 'on',
            'version' => '2.2',
            'q' => $searchText,
            'fq' => $filterQuery,
            'facet' => 'true',
            'facet.field' => 'm_class_name',
            'f.m_class_name.facet.mincount' => '0',
            'facet.sort' => 'true'
        );

        eZDebug::writeDebug( $queryParams );
        $searchURI = eZSolr::buildHTTPGetQuery( $this->SearchServerURI . '/select', $queryParams );
        eZDebug::writeDebug( $searchURI, 'search URI' );
        $data = file_get_contents( $searchURI );
        eZDebug::writeDebug( $data );

        include_once( 'lib/ezxml/classes/ezxml.php' );
        $xml = new eZXML();
        $dom =& $xml->domTree( $data );

        if ( $dom )
        {
            $response =& $dom->root();
            $result = $response->elementByName( 'result' );
            $searchCount = $result->attributeValue( 'numFound' );

            $objectRes = array();
            $docs = $result->elementsByName( 'doc' );
            foreach ( $docs as $doc )
            {
                $mainNodeIDElement = $doc->elementByAttributeValue( 'name', 'm_main_node_id' );
                if ( $mainNodeIDElement )
                {
                    $objectRes[] = eZContentObjectTreeNode::fetch( $mainNodeIDElement->textContent() );
                }
            }
        }

        $stopWordArray = array();

        return array(
            "SearchResult" => $objectRes,
            "SearchCount" => $searchCount,
            "StopWordArray" => $stopWordArray,
        );
    }

    /*
     \brief Sends a HTTP post request to the search server
     \todo see if we can use eZHTTPTool::sendHTTPRequest instead
    */
    function post( $url, $postData )
    {
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_POST, 1 );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $postData );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
        $data = curl_exec( $ch );

        $errNo = curl_errno( $ch );
        if  ( $errNo )
        {
            eZDebug::writeError( 'curl error: ' . $errNo, 'eZSolr::post' );
        }
        else
        {
            if ( strstr ( $data, '<result status="0"></result>'))
            {
                return true;
            }
            else
            {
                eZDebug::writeError( $data, 'eZSolr::post' );
            }
        }

        curl_close( $ch );
    }

    /*!
     \build a HTTP GET query
     \todo see if we can use eZHTTPTool::sendHTTPRequest instead
    */
    function buildHTTPGetQuery( $baseURL, $queryParams )
    {
        foreach ( $queryParams as $name => $value )
        {
            if ( is_array( $value ) )
            {
                foreach ( $value as $valueKey => $valuePart )
                {
                    $encodedQueryParams[] = urlencode( $name ) . '=' . urlencode( $valuePart );
                }
            }
            else
            {
                $encodedQueryParams[] = urlencode( $name ) . '=' . urlencode( $value );
            }
        }

        $url = $baseURL . '?' . implode( '&', $encodedQueryParams );
        return $url;
    }

    function supportedSearchTypes()
    {
        $searchTypes = array( array( 'type' => 'attribute',
                                     'subtype' =>  'fulltext',
                                     'params' => array( 'classattribute_id', 'value' ) ),
                              array( 'type' => 'attribute',
                                     'subtype' =>  'patterntext',
                                     'params' => array( 'classattribute_id', 'value' ) ),
                              array( 'type' => 'attribute',
                                     'subtype' =>  'integer',
                                     'params' => array( 'classattribute_id', 'value' ) ),
                              array( 'type' => 'attribute',
                                     'subtype' =>  'integers',
                                     'params' => array( 'classattribute_id', 'values' ) ),
                              array( 'type' => 'attribute',
                                     'subtype' =>  'byrange',
                                     'params' => array( 'classattribute_id' , 'from' , 'to'  ) ),
                              array( 'type' => 'attribute',
                                     'subtype' => 'byidentifier',
                                     'params' => array( 'classattribute_id', 'identifier', 'value' ) ),
                              array( 'type' => 'attribute',
                                     'subtype' => 'byidentifierrange',
                                     'params' => array( 'classattribute_id', 'identifier', 'from', 'to' ) ),
                              array( 'type' => 'attribute',
                                     'subtype' => 'integersbyidentifier',
                                     'params' => array( 'classattribute_id', 'identifier', 'values' ) ),
                              array( 'type' => 'fulltext',
                                     'subtype' => 'text',
                                     'params' => array( 'value' ) ) );
        $generalSearchFilter = array( array( 'type' => 'general',
                                             'subtype' => 'class',
                                             'params' => array( array( 'type' => 'array',
                                                                       'value' => 'value'),
                                                                'operator' ) ),
                                      array( 'type' => 'general',
                                             'subtype' => 'publishdate',
                                             'params'  => array( 'value', 'operator' ) ),
                                      array( 'type' => 'general',
                                             'subtype' => 'subtree',
                                             'params'  => array( array( 'type' => 'array',
                                                                        'value' => 'value'),
                                                                 'operator' ) ) );
        return array( 'types' => $searchTypes,
                      'general_filter' => $generalSearchFilter );
    }
}

?>
