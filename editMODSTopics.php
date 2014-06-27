#!/usr/bin/env drush

#<?php

/**
 * This script is designed to transform MODS records which contain: 
 * 
 * <subject>
 *   <topic>Education</topic>
 *   <topic>Health Education</topic>
 *   <topic>Other Data</topic>
 * </subject>
 * 
 * into records that look like this:
 * 
 * <subject>
 *   <topic>Education</topic>
 * </subject>
 * <subject>
 *   <topic>Health Education</topic>
 * </subject>
 * <subject>
 *   <topic>Other Data</topic>
 * </subject>
 */


# include all php files necessary for Tuque
foreach ( glob("/var/www/drupal/htdocs/sites/all/libraries/tuque/*.php") as $filename) {
	require_once($filename);
}

# repository connection parameters
$url = 'localhost:8080/fedora';
$username = 'fedoraAdmin';
$password = 'fedoraAdmin';

# set up connection and repository variables
$connection = new RepositoryConnection($url, $username, $password);
$api = new FedoraApi($connection);
$repository = new FedoraRepository($api, new SimpleCache());

# query to grab all pdf collection objects from the repository
$sparqlQuery = "SELECT ?s
                FROM <#ri>
                WHERE {
                    ?s <info:fedora/fedora-system:def/relations-external#isMemberOfCollection> 
                    <info:fedora/islandora:sp_pdf_collection> .
                }";

# run query
drush_print("\n*****Querying repository for all PDF objects...");
$allPDFObjects = $repository->ri->sparqlQuery($sparqlQuery);
drush_print("\n*****Query complete*****\n");

// main loop for ALL PDF OBJECTS in the collection
$totalNumObjects = count($allPDFObjects);
drush_print("There are $totalNumObjects objects to be processed");

// establish a counter for how many objects we edit
$objectsChanged = 0;

drush_print("\n******Beginning main processing loop*****\n");
for ($counter = 0; $counter < $totalNumObjects; $counter++) {
    
    $theObject = $allPDFObjects[$counter];
    $realCount = $counter + 1;
    drush_print("Processing record $realCount of $totalNumObjects");
    
    //print $theObject['s']['value'];
    $objectPID = $theObject['s']['value'];
       
    # try to fetch PID from repo
    try {
        //drush_print("Attempting to access $objectPID from repository");
        $object = $repository->getObject($objectPID);
    }
    catch (Exception $e) {
        drush_print("\n\n**********#######  ERROR  #######*********");
        drush_print("***Could not get object $objectPID from repo***\n\n");
        continue;
    }
    
    # grab the MODS data stream
    $modsDS = $object['MODS'];
    
    /****************MODS RECORD**********************/
    //drush_print("Editing MODS record");
    $modsDOMDoc = new DOMDocument();
    $modsDOMDoc->preserveWhiteSpace = false;
    $modsDOMDoc->formatOutput = true;
    $modsDOMDoc->loadXML($modsDS->content);
    $modsXPath = new DOMXPath($modsDOMDoc);
    $modsXPath->registerNameSpace('mods', 'http://www.loc.gov/mods/v3');
    
    // flag to indicate if datastream reingest and DC regen is needed
    $updateThisRecord = FALSE;
    
    $domElemsToRemove = array();
    
    // loop through all <subject> nodes
    foreach ($modsXPath->query('//mods:subject') as $node) {
        
        // loop through all the <topic> nodes that are children of <subject> nodes
        // everytime we find a topic node, we create a new <subject><topic/></subject>
        // trio and insert it in the DOM before the original <subject> node
        foreach ($modsXPath->query('mods:topic', $node) as $topicNode) {
            
            $newSubjectNode = $modsDOMDoc->createElement('subject');
            $newTopicNode = $modsDOMDoc->createElement('topic', $topicNode->nodeValue);
            
            $newNode = $node->parentNode->insertBefore($newSubjectNode, $node);
            $newNode->appendChild($newTopicNode);
            
            $updateThisRecord = TRUE;
            
            // add this subject node to a list to be removed as it contains multiple topics
            $domElemsToRemove[] = $node;
        }
    }
    
    if (!empty($domElemsToRemove)) {
        // our array may have duplicate elements in it, let's remove those
        $domElemsToRemove = array_unique($domElemsToRemove);
        
        // remove all subject nodes that contained multiple topics
        foreach( $domElemsToRemove as $toBeRemoved) {
            $toBeRemoved->parentNode->removeChild($toBeRemoved);
        }
    }
    
    if ($updateThisRecord) {
        // write the new updated info back into the datastream
        $modsDS->setContentFromString($modsDOMDoc->saveXML($modsDOMDoc->documentElement));
        
        # ingest edited datastream into the repository
        $object->ingestDatastream($modsDS);
        
        //drush_print("MODS record updated for object pid: $objectPID\n");
        /*************MODS RECORD COMPLETE*****************/
        
        
        /******************DUBLIN CORE ********************/
        //drush_print("Re-generating Dublin Core");
        // update the DC based on the MODS record
        $document = new DOMDocument();
        $document->loadXML($modsDS->content);
        $transform = 'mods_to_dc.xsl';
        
        // the magic call
        xml_form_builder_update_dc_datastream($object, $transform, $document);
        
        //drush_print("Dublin core regenerated");
        /*************DUBLIN CORE COMPLETE*****************/
        
        // keep track of how many objects we edited
        $objectsChanged++;
        
    }
}
drush_print("Main processing loop complete");
drush_print("$objectsChanged out of $totalNumObjects were updated");
echo "\n\nAll operations complete\n";
