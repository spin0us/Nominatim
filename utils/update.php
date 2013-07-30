#!/usr/bin/php -Cq
<?php

        require_once(dirname(dirname(__FILE__)).'/lib/init-cmd.php');
        ini_set('memory_limit', '800M');

	$aCMDOptions = array(
		"Import / update / index osm data",
		array('help', 'h', 0, 1, 0, 0, false, 'Show Help'),
		array('quiet', 'q', 0, 1, 0, 0, 'bool', 'Quiet output'),
		array('verbose', 'v', 0, 1, 0, 0, 'bool', 'Verbose output'),

		array('max-load', '', 0, 1, 1, 1, 'float', 'Maximum load average - indexing is paused if this is exceeded'),
		array('max-blocking', '', 0, 1, 1, 1, 'int', 'Maximum blocking processes - indexing is aborted / paused if this is exceeded'),

		array('import-osmosis', '', 0, 1, 0, 0, 'bool', 'Import using osmosis'),
		array('import-osmosis-all', '', 0, 1, 0, 0, 'bool', 'Import using osmosis forever'),
		array('no-npi', '', 0, 1, 0, 0, 'bool', 'Do not write npi index files'),
		array('no-index', '', 0, 1, 0, 0, 'bool', 'Do not index the new data'),

		array('import-npi-all', '', 0, 1, 0, 0, 'bool', 'Import npi pre-indexed files'),

		array('import-hourly', '', 0, 1, 0, 0, 'bool', 'Import hourly diffs'),
		array('import-daily', '', 0, 1, 0, 0, 'bool', 'Import daily diffs'),
		array('import-all', '', 0, 1, 0, 0, 'bool', 'Import all available files'),

		array('import-file', '', 0, 1, 1, 1, 'realpath', 'Re-import data from an OSM file'),
		array('import-diff', '', 0, 1, 1, 1, 'realpath', 'Import a diff (osc) file from local file system'),

		array('import-node', '', 0, 1, 1, 1, 'int', 'Re-import node'),
		array('import-way', '', 0, 1, 1, 1, 'int', 'Re-import way'),
		array('import-relation', '', 0, 1, 1, 1, 'int', 'Re-import relation'),
		array('import-from-main-api', '', 0, 1, 0, 0, 'bool', 'Use OSM API instead of Overpass to download objects'),

		array('index', '', 0, 1, 0, 0, 'bool', 'Index'),
		array('index-rank', '', 0, 1, 1, 1, 'int', 'Rank to start indexing from'),
		array('index-instances', '', 0, 1, 1, 1, 'int', 'Number of indexing instances (threads)'),
		array('index-estrate', '', 0, 1, 1, 1, 'int', 'Estimated indexed items per second (def:30)'),

		array('deduplicate', '', 0, 1, 0, 0, 'bool', 'Deduplicate tokens'),
	);
	getCmdOpt($_SERVER['argv'], $aCMDOptions, $aResult, true, true);

	if ($aResult['import-hourly'] + $aResult['import-daily'] + isset($aResult['import-diff']) > 1)
	{
		showUsage($aCMDOptions, true, 'Select either import of hourly or daily');
	}

	if (!isset($aResult['index-instances'])) $aResult['index-instances'] = 1;
	if (!isset($aResult['index-rank'])) $aResult['index-rank'] = 0;
/*
	// Lock to prevent multiple copies running
	if (exec('/bin/ps uww | grep '.basename(__FILE__).' | grep -v /dev/null | grep -v grep -c', $aOutput2, $iResult) > 1)
	{
		echo "Copy already running\n";
		exit;
	}
	if (!isset($aResult['max-load'])) $aResult['max-load'] = 1.9;
	if (!isset($aResult['max-blocking'])) $aResult['max-blocking'] = 3;
	if (getBlockingProcesses() > $aResult['max-blocking'])
	{
		echo "Too many blocking processes for import\n";
		exit;
	}
*/

	// Assume osm2pgsql is in the folder above
	$sBasePath = dirname(dirname(__FILE__));

	$oDB =& getDB();

	$aDSNInfo = DB::parseDSN(CONST_Database_DSN);

	$bFirst = true;
	$bContinue = $aResult['import-all'];
	while ($bContinue || $bFirst)
	{
		$bFirst = false;

		if ($aResult['import-hourly'])
		{
			// Mirror the hourly diffs
			exec('wget --quiet --mirror -l 1 -P '.$sMirrorDir.' http://planet.openstreetmap.org/hourly');
			$sNextFile = $oDB->getOne('select TO_CHAR(lastimportdate,\'YYYYMMDDHH24\')||\'-\'||TO_CHAR(lastimportdate+\'1 hour\'::interval,\'YYYYMMDDHH24\')||\'.osc.gz\' from import_status');
			$sNextFile = $sMirrorDir.'planet.openstreetmap.org/hourly/'.$sNextFile;
			$sUpdateSQL = 'update import_status set lastimportdate = lastimportdate+\'1 hour\'::interval';
		}

		if ($aResult['import-daily'])
		{
			// Mirror the daily diffs
			exec('wget --quiet --mirror -l 1 -P '.$sMirrorDir.' http://planet.openstreetmap.org/daily');
			$sNextFile = $oDB->getOne('select TO_CHAR(lastimportdate,\'YYYYMMDD\')||\'-\'||TO_CHAR(lastimportdate+\'1 day\'::interval,\'YYYYMMDD\')||\'.osc.gz\' from import_status');
			$sNextFile = $sMirrorDir.'planet.openstreetmap.org/daily/'.$sNextFile;
			$sUpdateSQL = 'update import_status set lastimportdate = lastimportdate::date + 1';
		}
		
		if (isset($aResult['import-diff']))
		{
			// import diff directly (e.g. from osmosis --rri)
			$sNextFile = $aResult['import-diff'];
			if (!file_exists($sNextFile))
			{
				echo "Cannot open $sNextFile\n";
				exit;
			}
			// Don't update the import status - we don't know what this file contains
			$sUpdateSQL = 'update import_status set lastimportdate = now() where false';
		}

		// Missing file is not an error - it might not be created yet
		if (($aResult['import-hourly'] || $aResult['import-daily'] || isset($aResult['import-diff'])) && file_exists($sNextFile))
		{
			// Import the file
			$sCMD = CONST_Osm2pgsql_Binary.' -klas -C 2000 -O gazetteer -d '.$aDSNInfo['database'].' '.$sNextFile;
			echo $sCMD."\n";
			exec($sCMD, $sJunk, $iErrorLevel);

			if ($iErrorLevel)
			{
				echo "Error from osm2pgsql, $iErrorLevel\n";
				exit;
			}
	
			// Move the date onwards
			$oDB->query($sUpdateSQL);
		}
		else
		{
			$bContinue = false;
		}
	}

	$bModifyXML = false;
	$sModifyXMLstr = '';
	$bUseOSMApi = isset($aResult['import-from-main-api']) && $aResult['import-from-main-api'];
	if (isset($aResult['import-file']) && $aResult['import-file'])
	{
		$bModifyXML = true;
	}
	if (isset($aResult['import-node']) && $aResult['import-node'])
	{
		$bModifyXML = true;
		if ($bUseOSMApi)
		{
			$sModifyXMLstr = file_get_contents('http://www.openstreetmap.org/api/0.6/node/'.$aResult['import-node']);
		}
		else
		{
			$sModifyXMLstr = file_get_contents('http://overpass.osm.rambler.ru/cgi/interpreter?data=node('.$aResult['import-node'].');out%20meta;');
		}
	}
	if (isset($aResult['import-way']) && $aResult['import-way'])
	{
		$bModifyXML = true;
		if ($bUseOSMApi)
		{
			$sCmd = 'http://www.openstreetmap.org/api/0.6/way/'.$aResult['import-way'].'/full';
		}
		else
		{
			$sCmd = 'http://overpass.osm.rambler.ru/cgi/interpreter?data=(way('.$aResult['import-way'].');node(w););out%20meta;';
		}
		$sModifyXMLstr = file_get_contents($sCmd);
	}
	if (isset($aResult['import-relation']) && $aResult['import-relation'])
	{
		$bModifyXML = true;
		if ($bUseOSMApi)
		{
			$sModifyXMLstr = file_get_contents('http://www.openstreetmap.org/api/0.6/relation/'.$aResult['import-relation'].'/full');
		}
		else
		{
			$sModifyXMLstr = file_get_contents('http://overpass.osm.rambler.ru/cgi/interpreter?data=((rel('.$aResult['import-relation'].');way(r);node(w));node(r));out%20meta;');
		}
	}
	if ($bModifyXML)
	{
		// derive change from normal osm file with osmosis
		$sTemporaryFile = CONST_BasePath.'/data/osmosischange.osc';
		if (isset($aResult['import-file']) && $aResult['import-file'])
		{
			$sCMD = CONST_Osmosis_Binary.' --read-xml \''.$aResult['import-file'].'\' --read-empty --derive-change --write-xml-change '.$sTemporaryFile;
			echo $sCMD."\n";
			exec($sCMD, $sJunk, $iErrorLevel);
			if ($iErrorLevel)
			{
				echo "Error converting osm to osc, osmosis returned: $iErrorLevel\n";
				exit;
			}
		}
		else
		{
			$aSpec = array(
				0 => array("pipe", "r"),  // stdin
				1 => array("pipe", "w"),  // stdout
				2 => array("pipe", "w") // stderr
			);
			$sCMD = CONST_Osmosis_Binary.' --read-xml - --read-empty --derive-change --write-xml-change '.$sTemporaryFile;
			echo $sCMD."\n";
			$hProc = proc_open($sCMD, $aSpec, $aPipes);
			if (!is_resource($hProc))
			{
				echo "Error converting osm to osc, osmosis failed\n";
				exit;
			}
			fwrite($aPipes[0], $sModifyXMLstr);
			fclose($aPipes[0]);
			$sOut = stream_get_contents($aPipes[1]);
			if ($aResult['verbose']) echo $sOut;
			fclose($aPipes[1]);
			$sErrors = stream_get_contents($aPipes[2]);
			if ($aResult['verbose']) echo $sErrors;
			fclose($aPipes[2]);
			if ($iError = proc_close($hProc))
			{
				echo "Error converting osm to osc, osmosis returned: $iError\n";
				echo $sOut;
				echo $sErrors;
				exit;
			}
		}

		// import generated change file
		$sCMD = CONST_Osm2pgsql_Binary.' -klas -C 2000 -O gazetteer -d '.$aDSNInfo['database'].' '.$sTemporaryFile;
		echo $sCMD."\n";
		exec($sCMD, $sJunk, $iErrorLevel);
		if ($iErrorLevel)
		{
			echo "osm2pgsql exited with error level $iErrorLevel\n";
			exit;
		}
	}

	if ($aResult['deduplicate'])
	{
                $oDB =& getDB();
                $sSQL = 'select partition from country_name order by country_code';
                $aPartitions = $oDB->getCol($sSQL);
                if (PEAR::isError($aPartitions))
                {
                        fail($aPartitions->getMessage());
                }
                $aPartitions[] = 0;

		$sSQL = "select word_token,count(*) from word where substr(word_token, 1, 1) = ' ' and class is null and type is null and country_code is null group by word_token having count(*) > 1 order by word_token";
		$aDuplicateTokens = $oDB->getAll($sSQL);
		foreach($aDuplicateTokens as $aToken)
		{
			if (trim($aToken['word_token']) == '' || trim($aToken['word_token']) == '-') continue;
			echo "Deduping ".$aToken['word_token']."\n";
			$sSQL = "select word_id,(select count(*) from search_name where nameaddress_vector @> ARRAY[word_id]) as num from word where word_token = '".$aToken['word_token']."' and class is null and type is null and country_code is null order by num desc";
			$aTokenSet = $oDB->getAll($sSQL);
			if (PEAR::isError($aTokenSet))
			{
				var_dump($aTokenSet, $sSQL);
				exit;
			}
			
			$aKeep = array_shift($aTokenSet);
			$iKeepID = $aKeep['word_id'];

			foreach($aTokenSet as $aRemove)
			{
				$sSQL = "update search_name set";
				$sSQL .= " name_vector = (name_vector - ".$aRemove['word_id'].")+".$iKeepID.",";
				$sSQL .= " nameaddress_vector = (nameaddress_vector - ".$aRemove['word_id'].")+".$iKeepID;
				$sSQL .= " where name_vector @> ARRAY[".$aRemove['word_id']."]";
				$x = $oDB->query($sSQL);
				if (PEAR::isError($x))
				{
					var_dump($x);
					exit;
				}

				$sSQL = "update search_name set";
				$sSQL .= " nameaddress_vector = (nameaddress_vector - ".$aRemove['word_id'].")+".$iKeepID;
				$sSQL .= " where nameaddress_vector @> ARRAY[".$aRemove['word_id']."]";
				$x = $oDB->query($sSQL);
				if (PEAR::isError($x))
				{
					var_dump($x);
					exit;
				}

				$sSQL = "update location_area_country set";
				$sSQL .= " keywords = (keywords - ".$aRemove['word_id'].")+".$iKeepID;
				$sSQL .= " where keywords @> ARRAY[".$aRemove['word_id']."]";
				$x = $oDB->query($sSQL);
				if (PEAR::isError($x))
				{
					var_dump($x);
					exit;
				}

				foreach ($aPartitions as $sPartition)
				{
					$sSQL = "update search_name_".$sPartition." set";
					$sSQL .= " name_vector = (name_vector - ".$aRemove['word_id'].")+".$iKeepID.",";
					$sSQL .= " nameaddress_vector = (nameaddress_vector - ".$aRemove['word_id'].")+".$iKeepID;
					$sSQL .= " where name_vector @> ARRAY[".$aRemove['word_id']."]";
					$x = $oDB->query($sSQL);
					if (PEAR::isError($x))
					{
						var_dump($x);
						exit;
					}

					$sSQL = "update search_name_".$sPartition." set";
					$sSQL .= " nameaddress_vector = (nameaddress_vector - ".$aRemove['word_id'].")+".$iKeepID;
					$sSQL .= " where nameaddress_vector @> ARRAY[".$aRemove['word_id']."]";
					$x = $oDB->query($sSQL);
					if (PEAR::isError($x))
					{
						var_dump($x);
						exit;
					}

					$sSQL = "update location_area_country set";
					$sSQL .= " keywords = (keywords - ".$aRemove['word_id'].")+".$iKeepID;
					$sSQL .= " where keywords @> ARRAY[".$aRemove['word_id']."]";
					$x = $oDB->query($sSQL);
					if (PEAR::isError($x))
					{
						var_dump($x);
						exit;
					}
				}

				$sSQL = "delete from word where word_id = ".$aRemove['word_id'];
				$x = $oDB->query($sSQL);
				if (PEAR::isError($x))
				{
					var_dump($x);
					exit;
				}
			}

		}
	}

	if ($aResult['index'])
	{
		passthru(CONST_BasePath.'/nominatim/nominatim -i -d '.$aDSNInfo['database'].' -t '.$aResult['index-instances'].' -r '.$aResult['index-rank']);
	}

	if ($aResult['import-osmosis'] || $aResult['import-osmosis-all'])
	{
		$sImportFile = CONST_BasePath.'/data/osmosischange.osc';
		$sOsmosisCMD = CONST_Osmosis_Binary;
		$sOsmosisConfigDirectory = CONST_BasePath.'/settings';
		$sCMDDownload = $sOsmosisCMD.' --read-replication-interval workingDirectory='.$sOsmosisConfigDirectory.' --simplify-change --write-xml-change '.$sImportFile;
		$sCMDImport = CONST_Osm2pgsql_Binary.' -klas -C 2000 -O gazetteer -d '.$aDSNInfo['database'].' '.$sImportFile;
		$sCMDIndex = $sBasePath.'/nominatim/nominatim -i -d '.$aDSNInfo['database'].' -t '.$aResult['index-instances'];
		if (!$aResult['no-npi']) {
			$sCMDIndex .= '-F ';
		}
		while(true)
		{
			$fStartTime = time();
			$iFileSize = 1001;

			// Logic behind this is that osm2pgsql locks the database quite a bit
			// So it is better to import lots of small files
			// But indexing works most efficiently on large amounts of data
			// So do lots of small imports and a BIG index

//			while($aResult['import-osmosis-all'] && $iFileSize > 1000)
//			{
				if (!file_exists($sImportFile))
				{
					// Use osmosis to download the file
					$fCMDStartTime = time();
					echo $sCMDDownload."\n";
					exec($sCMDDownload, $sJunk, $iErrorLevel);
					while ($iErrorLevel == 1)
					{
						echo "Error: $iErrorLevel\n";
						sleep(60);
						echo 'Re-trying: '.$sCMDDownload."\n";
						exec($sCMDDownload, $sJunk, $iErrorLevel);
					}
					$iFileSize = filesize($sImportFile);
					$sBatchEnd = getosmosistimestamp($sOsmosisConfigDirectory);
					echo "Completed for $sBatchEnd in ".round((time()-$fCMDStartTime)/60,2)." minutes\n";
					$sSQL = "INSERT INTO import_osmosis_log values ('$sBatchEnd',$iFileSize,'".date('Y-m-d H:i:s',$fCMDStartTime)."','".date('Y-m-d H:i:s')."','osmosis')";
					$oDB->query($sSQL);
				}

				$iFileSize = filesize($sImportFile);
				$sBatchEnd = getosmosistimestamp($sOsmosisConfigDirectory);
		
				// Import the file
				$fCMDStartTime = time();
				echo $sCMDImport."\n";
				exec($sCMDImport, $sJunk, $iErrorLevel);
				if ($iErrorLevel)
				{
					echo "Error: $iErrorLevel\n";
					exit;
				}
				echo "Completed for $sBatchEnd in ".round((time()-$fCMDStartTime)/60,2)." minutes\n";
				$sSQL = "INSERT INTO import_osmosis_log values ('$sBatchEnd',$iFileSize,'".date('Y-m-d H:i:s',$fCMDStartTime)."','".date('Y-m-d H:i:s')."','osm2pgsql')";
				var_Dump($sSQL);
				$oDB->query($sSQL);

				// Archive for debug?
				unlink($sImportFile);
//			}

			$sBatchEnd = getosmosistimestamp($sOsmosisConfigDirectory);

			// Index file
			$sThisIndexCmd = $sCMDIndex;

			if (!$aResult['no-npi'])
			{
				$fCMDStartTime = time();
				$iFileID = $oDB->getOne('select nextval(\'file\')');
				if (PEAR::isError($iFileID))
				{
					echo $iFileID->getMessage()."\n";
					exit;
				} 
				$sFileDir = CONST_BasePath.'/export/diff/';
				$sFileDir .= str_pad(floor($iFileID/1000000), 3, '0', STR_PAD_LEFT);
				$sFileDir .= '/'.str_pad(floor($iFileID/1000) % 1000, 3, '0', STR_PAD_LEFT);

				if (!is_dir($sFileDir)) mkdir($sFileDir, 0777, true);
				$sThisIndexCmd .= $sFileDir;
				$sThisIndexCmd .= '/'.str_pad($iFileID % 1000, 3, '0', STR_PAD_LEFT);
				$sThisIndexCmd .= ".npi.out";

				preg_match('#^([0-9]{4})-([0-9]{2})-([0-9]{2})#', $sBatchEnd, $aBatchMatch);
				$sFileDir = CONST_BasePath.'/export/index/';
				$sFileDir .= $aBatchMatch[1].'/'.$aBatchMatch[2];

				if (!is_dir($sFileDir)) mkdir($sFileDir, 0777, true);
				file_put_contents($sFileDir.'/'.$aBatchMatch[3].'.idx', "$sBatchEnd\t$iFileID\n", FILE_APPEND);
			}

			if (!$aResult['no-index'])
			{
				echo "$sThisIndexCmd\n";
				exec($sThisIndexCmd, $sJunk, $iErrorLevel);
				if ($iErrorLevel)
				{
					echo "Error: $iErrorLevel\n";
					exit;
				}

				if (!$aResult['no-npi'])
				{
					$sFileDir = CONST_BasePath.'/export/diff/';
					$sFileDir .= str_pad(floor($iFileID/1000000), 3, '0', STR_PAD_LEFT);
					$sFileDir .= '/'.str_pad(floor($iFileID/1000) % 1000, 3, '0', STR_PAD_LEFT);

					$sThisIndexCmd = 'bzip2 -z9 '.$sFileDir.'/'.str_pad($iFileID % 1000, 3, '0', STR_PAD_LEFT).".npi.out";
					echo "$sThisIndexCmd\n";
					exec($sThisIndexCmd, $sJunk, $iErrorLevel);
					if ($iErrorLevel)
					{
						echo "Error: $iErrorLevel\n";
						exit;
					}

					rename($sFileDir.'/'.str_pad($iFileID % 1000, 3, '0', STR_PAD_LEFT).".npi.out.bz2",
						$sFileDir.'/'.str_pad($iFileID % 1000, 3, '0', STR_PAD_LEFT).".npi.bz2");
				}
			}

			echo "Completed for $sBatchEnd in ".round((time()-$fCMDStartTime)/60,2)." minutes\n";
			$sSQL = "INSERT INTO import_osmosis_log values ('$sBatchEnd',$iFileSize,'".date('Y-m-d H:i:s',$fCMDStartTime)."','".date('Y-m-d H:i:s')."','index')";
			$oDB->query($sSQL);

			$sSQL = "update import_status set lastimportdate = '$sBatchEnd'";
			$oDB->query($sSQL);

			$fDuration = time() - $fStartTime;
			echo "Completed for $sBatchEnd in ".round($fDuration/60,2)."\n";
			if (!$aResult['import-osmosis-all']) exit;

			echo "Sleeping ".max(0,60-$fDuration)." seconds\n";
			sleep(max(0,60-$fDuration));
		}

	}

	if ($aResult['import-npi-all'])
	{
		$iNPIID = $oDB->getOne('select max(npiid) from import_npi_log');
		if (PEAR::isError($iNPIID))
		{
			var_dump($iNPIID);
			exit;
		}
		$sConfigDirectory = CONST_BasePath.'/settings';
		$sCMDImportTemplate = $sBasePath.'/nominatim/nominatim -d gazetteer -P 5433 -I -T '.$sBasePath.'/nominatim/partitionedtags.def -F ';
		while(true)
		{
			$fStartTime = time();

			$iNPIID++;

			$sImportFile = CONST_BasePath.'/export/diff/';
			$sImportFile .= str_pad(floor($iNPIID/1000000), 3, '0', STR_PAD_LEFT);
			$sImportFile .= '/'.str_pad(floor($iNPIID/1000) % 1000, 3, '0', STR_PAD_LEFT);
			$sImportFile .= '/'.str_pad($iNPIID % 1000, 3, '0', STR_PAD_LEFT);
			$sImportFile .= ".npi";
			while(!file_exists($sImportFile) && !file_exists($sImportFile.'.bz2'))
			{
				echo "sleep (waiting for $sImportFile)\n";
				sleep(10);
			}
			if (file_exists($sImportFile.'.bz2')) $sImportFile .= '.bz2';

			$iFileSize = filesize($sImportFile);
		
			// Import the file
			$fCMDStartTime = time();
			$sCMDImport = $sCMDImportTemplate . $sImportFile;
			echo $sCMDImport."\n";
			exec($sCMDImport, $sJunk, $iErrorLevel);
			if ($iErrorLevel)
			{
				echo "Error: $iErrorLevel\n";
				exit;
			}
			$sBatchEnd = $iNPIID;
			echo "Completed for $sBatchEnd in ".round((time()-$fCMDStartTime)/60,2)." minutes\n";
			$sSQL = "INSERT INTO import_npi_log values ($iNPIID, null, $iFileSize,'".date('Y-m-d H:i:s',$fCMDStartTime)."','".date('Y-m-d H:i:s')."','import')";
			var_Dump($sSQL);
			$oDB->query($sSQL);
		}
		
	}

	function getosmosistimestamp($sOsmosisConfigDirectory)
	{
		$sStateFile = file_get_contents($sOsmosisConfigDirectory.'/state.txt');
		preg_match('#timestamp=(.+)#', $sStateFile, $aResult);
		return str_replace('\:',':',$aResult[1]);
	}
