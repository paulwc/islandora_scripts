#!/usr/bin/env drush

#<?php
/**
 * This script is intended to remove multiple versions of a datastream that are the same
 * and grouped together in the versions list. It uses the checksum value of the oldest datastream
 * version and compares it to the next most recent.
 * 
 * If an object datastream does not have a checksum it is NOT skipped and is checked against the
 * next version's checksum. Two consecutive datastream versions without checksums are considered IDENTICAL.
 * 
 * Example: if we list all versions of a datastream, starting from the oldest version and 'AAA' denotes a
 * datastream checksum value, we can represent the steps of this script from left to right
 *  
 * AAA -> AAA -> AAA  
 * AAA -> BBB -> BBB
 * AAA -> BBB -> CCC
 * BBB -> CCC -> DDD
 * BBB -> DDD -> EEE
 * CCC -> EEE -> AAA
 * DDD -> AAA
 * EEE
 * AAA
 * 
 * 9 versions down to 6 versions
 * 
 * Usage: pass the name of the collection to the script as the first argument
 *
 * Example: drush php-script purgeDSVersions.php collection_name
 *
 * @author Paul Church
 * @date July 2014
 */


/**
 * A note about the TUQUE purgeDatastream API call:
 * 
 * startDT and endDT are inclusive, so we need to subtract time from the
 * endDT value if we want to keep the most recent version of the
 * datastream or add time if we want to keep the oldest version of the ds
 */


/**
 * Creates a custom formatted ISO8601-ish datetime string
 * from a datastream array generated by the 
 * getDatastreamHistory() function
 * 
 * 
 * @param unknown $dsObject
 * @param boolean $modify optional, a string to modify the datetime, ex) "-1 second"
 * @return string
 */
function createCustomDT($dsObject, $modify='') 
{
    $dt = new DateTime($dsObject['dsCreateDate']);
    
    if (!$modify=='') {
        $dt->modify($modify);
    }
    
    // create datetime string to conform to values expected by fedora API
    $customDT = date_format($dt, 'Y-m-d\TH:i:s.u');
    $customDT = substr($customDT, 0, count($customDT)-4) . "Z";
    
    return $customDT;
}

/**
 * Creates a custom formatted ISO8601-ish datetime string with 
 * 3 digits of microseconds from a datastream object generated
 * by the getDatastreamHistory() function
 * 
 * Example: 2014-07-08T20:21:01.223Z
 * 
 * If a "-1" or "+1" is passed to this function, it will subtract or delete
 * one microsecond from the datetime string and return it
 * 
 * $dsObject['dsCreateDate'] = '2014-07-08T20:21:01.223Z'
 * Example: createMicrosecondDT($dsObject, "-1")
 * will return '2014-07-08T20:21:01.222Z'
 * 
 * 
 * @param array $dsObject a datastream object generated by the getDatastreamHistory() function
 * @param string $modify (optional) either a '-1' or a '+1' 
 * @return string
 */
function createMicrosecondDT($dsObject, $modify='')
{
    $dt = new DateTime($dsObject['dsCreateDate']);
    /* Microseconds as stored in a DateTime object are 6 digits long
     * but we only want 3 digits, so we chop off the last 3 digits
     * Ex: input: dt = 2014-07-08T20:21:01.223Z; 
     * $microseconds = $dt->format('u');  
     * $microseconds would equal "223000"
     * Note the un-needed extra 3 zeroes at the end
     */    
    $microseconds = substr($dt->format('u'), 0, 3);
    
    if (!$modify=='') {
        
        if ($modify == '-1') {
            if ($microseconds == '000') {
                $dt->modify('-1 second');
                $customDT = date_format($dt, 'Y-m-d\TH:i:s.') . '999Z';
                return $customDT;
            }
            $microseconds -= 1;
        }
        else if ($modify == '+1') {
            if ($microseconds == '999') {
                $dt->modify('+1 second');
                $customDT = date_format($dt, 'Y-m-d\TH:i:s.') . '000Z';
                return $customDT;
            }
            $microseconds += 1;
        }
    }
    
    // create datetime string to conform to values expected by fedora API
    $customDT = date_format($dt, 'Y-m-d\TH:i:s.') . $microseconds . "Z";
    
    return $customDT;
    
}

/**
 * Taken from stackoverflow: 
 * https://stackoverflow.com/questions/2510434/format-bytes-to-kilobytes-megabytes-gigabytes
 * 
 * @param unknown $size
 * @param number $precision
 * @return string
 */
function formatBytes($size, $precision = 2)
{
    $base = log($size) / log(1024);
    $suffixes = array('', 'kB', 'MB', 'GB', 'TB');

    return round(pow(1024, $base - floor($base)), $precision) . $suffixes[floor($base)];
}


// grab the first user supplied parameter as the name of the collection
$collection = drush_shift();

if (! $collection) {
    drush_print("***Error: please provide the name of the collection as the first argument");
    drush_print("Example: drush scr purgeDSVersions.php islandora:collection_name_here FULL_TEXT");
    return;
}

// grab the second user supplied paramter as the name of the datastream we care about
$dslabel = drush_shift();

if (! $dslabel) {
    drush_print("***ERROR: please provide the name of the datastream label as the second argument");
    drush_print("Example: drush scr purgeDSVersions.php islandora:collection_name_here FULL_TEXT");
    return;
}

// include all php files necessary for Tuque
foreach (glob("/var/www/drupal/htdocs/sites/all/libraries/tuque/*.php") as $filename) {
    require_once ($filename);
}

// repository connection parameters
$url = 'localhost:8080/fedora';
$username = 'fedoraAdmin';
$password = 'fedoraAdmin';

// set up connection and repository variables
$connection = new RepositoryConnection($url, $username, $password);
$api = new FedoraApi($connection);
$repository = new FedoraRepository($api, new SimpleCache());
$api_m = $repository->api->m; // Fedora management API

// query to grab all pdf collection objects from the repository
$sparqlQuery = "SELECT ?s
                FROM <#ri>
                WHERE {
                    ?s <info:fedora/fedora-system:def/relations-external#isMemberOfCollection>
                    <info:fedora/$collection> .
                }";

// run query
drush_print("\nQuerying repository for all PDF objects...");
$allPDFObjects = $repository->ri->sparqlQuery($sparqlQuery);
drush_print("Query complete\n");

// check number of objects in the collection to make sure we have some
$totalNumObjects = count($allPDFObjects);
if ($totalNumObjects <= 0) {
    drush_print("***Error: no objects found in the given collection. Check the collection name.");
    drush_print("***No processing was completed. Exiting.");
    return;
} else {
    drush_print("There are $totalNumObjects objects to be processed");
}

// establish a counter for how many objects we edit
$objectsChanged = 0;

$spaceFreed = 0;

$startingDSNumber = 0;
$endingDSNumber = 0;

$objectsWithProblems = array();

drush_print("\nBeginning main processing loop\n");
for ($counter = 0; $counter < $totalNumObjects; $counter ++) {
    // grab the next object from the result set
    $theObject = $allPDFObjects[$counter];

    // increment the counter shown to the user
    $realCount = $counter + 1;
    drush_print("Processing record $realCount of $totalNumObjects");

    // grab the PID value from the object array
    $objectPID = $theObject['s']['value'];


    /***************TESTING*****************/
//     $pid = 'islandora:1';
    /***************************************/
    
    $dshistory = $api_m->getDatastreamHistory($objectPID, $dslabel); //NB: ds's are returned in order from most to least recent
    $oldestToNewestDS = array_reverse($dshistory);
    $startingDSNumber += count($oldestToNewestDS);
//     drush_print("Datastream array before pruning");
//     print_r($oldestToNewestDS);
//     drush_print("************************************************");
    // print_r($dshistory);
    // print_r($dshistory[0]);
    // print_r($dshistory[count($dshistory)-1]);
    
//     return;
    
    $oldestDS = $oldestToNewestDS[0];
    $mainCounter = count($oldestToNewestDS)-1;
    
    for ($i = 0; $i <= $mainCounter; $i++) {
//         drush_print("OUTER: value of i is $i");
        $currentDS = $oldestToNewestDS[$i];
        $nextDS    = $oldestToNewestDS[$i+1];
        if (!$currentDS || !$nextDS) {
            drush_print("OUTER: Nothing to compare anymore, finishing up");
            break;
        }
        drush_print('OUTER: Comparing checksum of '.$currentDS['dsVersionID'].' to checksum of '.$nextDS['dsVersionID']);
        
        $currentChecksum = $currentDS['dsChecksum'];
        $nextChecksum    = $nextDS['dsChecksum'];
        
        $toBeRemoved = array();
        
        if ($currentChecksum === $nextChecksum) {
            drush_print("OUTER: Checksums are the same, continuing");
            
            $toBeRemoved[] = $nextDS;
            
            for ($innerCounter = $i+1; $innerCounter <= count($oldestToNewestDS)-1; $innerCounter++) {
                $innerDSChecksum = $oldestToNewestDS[$innerCounter]['dsChecksum'];
                $nextInnerDSChecksum = $oldestToNewestDS[$innerCounter+1]['dsChecksum'];
                drush_print('   INNER: Comparing checksum of '.$oldestToNewestDS[$innerCounter]['dsVersionID'].' to checksum of '.$oldestToNewestDS[$innerCounter+1]['dsVersionID']);
                if ($innerDSChecksum === $nextInnerDSChecksum) {
                    $toBeRemoved[] = $oldestToNewestDS[$innerCounter+1];
                    continue;
                }
                else {
                    
	                $totalDSSize = 0;
                    foreach($toBeRemoved as $ds) {
                        $totalDSSize += $ds['dsSize'];
                    }
                    
                    // remove from $currentDS to $oldestToNewestDS[$innerCounter]
                    drush_print('   INNER: Checksums are different, removing from '.$currentDS['dsVersionID'].' to '.$oldestToNewestDS[$innerCounter]['dsVersionID']);
			        try{            
                        $api_m->purgeDatastream($objectPID, $dslabel, array(
                          'startDT' => createMicrosecondDT($currentDS, "+1"),
                          'endDT' => createMicrosecondDT($oldestToNewestDS[$innerCounter]),
                          'logMessage' => '',
                        ));

						$spaceFreed += $totalDSSize;

						$mainCounter = count($api_m->getDatastreamHistory($objectPID, $dslabel));
						$oldestToNewestDS = array_reverse($api_m->getDatastreamHistory($objectPID, $dslabel));
		//                 print_r($oldestToNewestDS);
						break;
                    }
                    catch (Exception $e) {
                        drush_print("***ERROR: skipping deletion of datastreams***");
                        $objectsWithProblems[] = $objectPID;
						break;
                    }
                }
            }
        }
        else {
            drush_print('OUTER: value of checksums for '.$currentDS['dsVersionID'].' and '.$nextDS['dsVersionID'].' are different, going to next DS');
            continue;
        }
    }
    
    $oldestToNewestDSEnd = array_reverse($api_m->getDatastreamHistory($objectPID, $dslabel));
//     drush_print("Datastream array after pruning");
//     print_r($oldestToNewestDSEnd);
    $endingDSNumber += count($oldestToNewestDSEnd);
}

print "\n";


if (!empty($objectsWithProblems)) {
    drush_print("The script encountered problems with the following objects");
    foreach ($objectsWithProblems as $prob) {
        drush_print($prob);
    }
}

drush_print("Number of datastreams before script: $startingDSNumber\nNumber of datastreams after script: $endingDSNumber");
drush_print("Amount of space freed : " . ($spaceFreed==0?0:formatBytes($spaceFreed, 3)));


return;
    
