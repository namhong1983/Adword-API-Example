<?php
/**
 * Created by PhpStorm.
 * User: Joseph Lukan
 * Date: 9/10/2017
 * Time: 11:56 AM
 */

// Handle Fatal Error
register_shutdown_function( "fatal_handler" );

// No displaying of error
//error_reporting(0);

// Allows mac detect line_endings in fgets methods
ini_set("auto_detect_line_endings", true);

// Setting currency format
setlocale(LC_MONETARY,"en_US");




include "vendor/autoload.php";



use Google\AdsApi\Common\OAuth2TokenBuilder;
use Google\AdsApi\AdWords\AdWordsSessionBuilder;
use Google\AdsApi\AdWords\AdWordsServices;
use Lazer\Classes\Database;
use Lazer\Classes\Relation;
use Ztobs\Classes\Ad;
use Ztobs\Classes\AddAdGroup;
use Ztobs\Classes\GetCampaigns;
use Ztobs\Classes\AddAds;
use Ztobs\Classes\GetAdGroupsByCampaign;
use Ztobs\Classes\GetAds;
use Ztobs\Classes\PauseAd;
use Ztobs\Classes\RemoveAd;
use Ztobs\Classes\AddCampaign;
use Ztobs\Classes\RemoveAdGroup;
use Ztobs\Classes\PauseAdGroup;
use Ztobs\Classes\AddKeywords;
use Ztobs\Classes\ResumeAd;
use Ztobs\Classes\ResumeAdGroup;
use Ztobs\Classes\RemoveKeyword;
use Ztobs\Classes\UpdateKeyword;






// Creating the Session from google oAuth
$oAuth2Credential = (new OAuth2TokenBuilder())
    ->fromFile()
    ->build();

// Construct an API session configured from a properties file and the OAuth2
// credentials above.
$session = (new AdWordsSessionBuilder())
    ->fromFile()
    ->withOAuth2Credential($oAuth2Credential)
    ->build();


$adwordServices = new AdWordsServices();


// Initialize campaigns from dashboard
$logfile = "";
$campaign_id = "";
$campaigns = [];
$er = false;
$feedPos = 0;
updateCampaigns();


//////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////

/*
 * Function fetches all campaigns into array
 * param:
 * return: array
 */
function getCampaigns()
{
    global $session;
    return GetCampaigns::run(new AdWordsServices(), $session);
}


/*
 * Function creates campaign
 * params: $name, $budget, $cap(optional)
 * return: array
 */
function createCampaign($name, $budget, $cap=null)
{
    global $session;
    $ret = AddCampaign::run(new AdWordsServices(), $session, $name, $budget, $cap);
    $id = $ret['id'];
    $name = $ret['name'];
    saveInTable(DB_CAMPAIGNS, ["campaign_id"=>$id, "campaign_name"=>$name], ["campaign_id"=>$id]);
    log_("Create Campaign: $name");
    return $ret;
}


function emptyFile($fileName)
{
    unlink($fileName);
    $sh = fopen($fileName, 'a+');
    fclose($sh);
}


function deleteLineInFile($file, $lineNumber)
{
    $file_out = file($file); // Read the whole file into an array
    //unset($file_out[$lineNumber-1]);
    $file_out[$lineNumber-1] = "\n";
    file_put_contents($file, implode("", $file_out));

}




function log_($data)
{
    global $logfile;
    $datetime = date("Y-m-d H:i:s");
    $stamp = str_replace(":", "_", str_replace(" ", "_", $datetime));
    $data = "[$datetime] $data";
    // log.2017-10-02-11:52:11.log
    $logfile = ($logfile!="")?$logfile:"log.$stamp.log";
    writeToFile($logfile, $data."\n");
}





/*
 * Function removed adgroup
 * param: adgroupId
 */
function removeAdGroup($adGroupId)
{
    global $session;
    RemoveAdGroup::run(new AdWordsServices(), $session, $adGroupId);
}





/*
 * Function finds the remaining details about the adgroup like product_name and line_number in adgroups.txt
 */
function findAdGroupData($adGroupToFind)
{
    global $campaign_id;
    $handle = fopen(TEMP_PATH.ADGROUPS_LOCAL_FILE, "a+");
    $cc = 1;
    $occur = null;
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            if(trim($line) != "")
            {
                $line_arr = explode("||", $line);
                $adGroudId = $line_arr[0];
                $adGroupName = trim($line_arr[1]);
                $camp_id = trim($line_arr[2]);

                if($adGroupName == $adGroupToFind && $camp_id == $campaign_id)
                {
                    $occur = array("adgroup_id"=>$adGroudId, "adgroup_name"=>$adGroupName, "line_number"=>$cc);
                }
            }
            $cc++;
        }
        fclose($handle);
    } else {
        // error opening the file.
    }
    return $occur;
}




/*
 * Function pauses adgroup and its ads and keywords
 */
function pauseAdGroup($adGroupId)
{
    global $session;
    PauseAdGroup::run(new AdWordsServices(), $session, $adGroupId);
}


/*
 *  Function creates ad groups into campaign supplied
 *  Params: Integer $campaign_id, String $adGroup_name, Integer $bid
 *  Returns: Integer
 */
function createAdGroup($campaign_id, $adGroup_name, $bid, $status)
{
    global $session;
    global $campaign_id;
    $id = AddAdGroup::run(new AdWordsServices(), $session, $campaign_id, $adGroup_name, $bid, $status);
    return $id;
}




function eligibleProduct($feed, $feedPos)
{
    global $er;
    $error = "";
    if(isEmpty($feed[0])) $error .= "Product Id, ";
    if(isEmpty($feed[2])) $error .= "Price, ";
    if(isEmpty($feed[5])) $error .= "Description, ";
    if(isEmpty($feed[6])) $error .= "Short Name, ";
    if(isEmpty($feed[10])) $error .= "Discount Percentage, ";
    if(isEmpty($feed[14])) $error .= "Product URL, ";
    if(isEmpty($feed[16])) $error .= "Status, ";

    if($error != "")
    {
        log_("**Error: Ad was not created because the following cannot be empty in the feed ($error) at FeedLine $feedPos");
        $er = true;
    }
    else return true;
}


function eligibleKeywords($feed, $feedPos)
{
    global $er;
    if(isEmpty($feed[12]))
    {
        log_("**Notice: No keyword found at FeedLine $feedPos");
        $er = true;
    }
    else return true;
}


function isEmpty($string)
{
    if($string == null || $string == "") return true;
    else return false;
}







/*
 * Function used to update the currently available campaigns
 */
function updateCampaigns()
{
    global $campaigns;
    $campaigns = getCampaigns();
    foreach ($campaigns as $campaign)
    {
        $name = $campaign['name'];
        $id = $campaign['id'];
        saveInTable(DB_CAMPAIGNS, ["campaign_id"=>$id, "campaign_name"=>$name], ["campaign_id"=>$id]);
    }
    return TRUE;
}


function updateAdGroups($campaigns, $campaign_id)
{
    if(file_exists(TEMP_PATH.ADGROUPS_LOCAL_FILE)) emptyFile(TEMP_PATH.ADGROUPS_LOCAL_FILE);
    $results = [];
    foreach ($campaigns as $campaign)
    {
        $name = $campaign['name'];
        $id = $campaign['id'];

        $adGroups = getAdGroups($id);
        if(count($adGroups) > 0)
        {
            foreach ($adGroups as $adGroup)
            {
                $adGroupName = $adGroup['name'];
                $adGroupId = $adGroup['id'];
                $results[] = array('id'=>$adGroupId, 'name'=>$adGroupName);
                writeToFile(ADGROUPS_LOCAL_FILE, "$adGroupId||$adGroupName||$campaign_id\n");
            }
        }
    }
    return $results;
}



function writeToFile($fileName, $data)
{
    $file = fopen(TEMP_PATH.$fileName, "a+");
    $resp = fwrite($file, $data);
    fclose($file);
    return $resp;
}


function getAdGroups($campaign_id)
{
    global $session;
    return GetAdGroupsByCampaign::run(new AdWordsServices(), $session, $campaign_id);
}


function getCampaignIdByName($name)
{
    global $campaigns;
    $id = null;
    foreach($campaigns as $campaign)
    {
        //echo "Manually updating adgroups and ads or using different script for existing campaign can lead to irrational behaviour and fatal error.\nMake sure same script runs a campaign always so that the local database in temp folder matches with adwords dashboard\nPlease cancel if violated\n\n";
        if($campaign['name'] == $name) $id = $campaign['id'];
    }

    if($id == null)
    {
        echo "The campaign name '$name' wasnt found, we are going to create it\n Type: y to continue \n";
        $stdin = fopen('php://stdin', 'r');
        $response = fgetc($stdin);

        if($response == "y")
        {
            $campaignData = createCampaign($name, CAMPAIGN_BUDGET);
            $id = $campaignData['id'];
        }
        else
        {
            exit();
        }
    }
    return $id;

}



function fatal_handler() {
    global $feedPos;
    global $argv;
    global $logfile;
    $errfile = "unknown file";
    $errstr  = "shutdown";
    $errno   = E_CORE_ERROR;
    $errline = 0;

    $error = error_get_last();

    if( $error !== NULL) {
        $errno   = $error["type"];
        $errfile = $error["file"];
        $errline = $error["line"];
        $errstr  = $error["message"];

        // If fatal error, restart script
        if($errno === E_ERROR)
        {
            $feedCont = $feedPos+1;
            echo "\nFatal Error at FeedLine $feedPos, check log for details\nRestarting script from Feedline $feedCont \n";
            log_("Fatal Error at FeedLine $feedPos: $errstr");
            // Removing adGroup that failed
            removeLastAdGroup();
            // re-run script with special options like, no-sync, startPos and no-cleanup
            system("php run.php ".$argv[1]." ".$argv[2]." no-sync ".$feedCont." ".$logfile);

        }
        else
        {
            echo "Error is not E_ERROR but ".$errno;
        }



    }
}



/*
 * Function removes last adgroup from database and adwords dashboard
 */
function removeLastAdGroup()
{
    $id = Database::table(DB_ADGROUPS)->lastId();
    if($id)
    {
        $row = Database::table(DB_ADGROUPS)->find($id);

        $adRow = Database::table(DB_ADS)->where('adgroup_id', '=', $row->adgroup_id)->find();
        if(!isset($adRow->id) && isset($row->id)) // if ad is not created but adgroup is created
        {
            Database::table(DB_ADGROUPS)->find($id)->delete();
            removeAdGroup($row->adgroup_id);
            log_("Adgroup: '".$row->adgroup_name."' Removed due to error creating ads");
        }
    }


}


function removeAd($adGroupId, $adId, $adName)
{
    global $session;
    RemoveAd::run(new AdWordsServices(), $session, $adGroupId, $adId);
    log_("Remove Ad: $adName **to be recreated**");
}



///////////////////////////////////////////////////////////////////////////////////////////////////
///                                                                                             ///
///////////////////////////////////////////////////////////////////////////////////////////////////

/*
 * Function to convert csv to array
 * @param: $filename
 * return: assoc 2D array of feeds
 */
function feedToArr($fileName, $feedStart)
{
    if(!filter_var($fileName, FILTER_VALIDATE_URL)) $fileName = FEED_PATH.$fileName; // appending file path if nto a url
    try
    {
        $file = fopen($fileName, 'r');
        $cc = 1;
        $result = [];
        while (($line = fgetcsv($file, 1000000, ";", '"')) !== FALSE) {
            //$line is an array of the csv elements
            if($cc > 1 && $cc >= $feedStart)$result[] = $line;
            $cc++;
        }
        fclose($file);
    }
    catch(Exception $e)
    {
        die("Invalid File");
    }
    return $result;
}



/*
 * Function to make total ads to create from product feeds,
 * params: $feedArr, $variation_arr
 * return: array of ads object
 */
function creator($feedArr, $variation_arr, $feedStart)
{
    global $campaign_id;
    global $feedPos;
    echo "Creating Adgroups, Ads and Keywords ....\n";
    $count = 0;
    foreach ($feedArr as $feed)
    {
        $feedPos = $feedStart+$count;
        echo "$feedPos,";

        $keywords_arr = explode(";", preg_replace('/[^A-Za-z0-9\-]/', '',$feed[12]));  //remove special characters and convert to array
        $product_url = $feed[14];
        $is_https = strpos($product_url, "https://");
        $product_url = str_replace("http://", "", $product_url);
        $product_url = str_replace("https://", "", $product_url);
        $finalUrl = $is_https?"https://".$product_url:"http://".$product_url;

        $ret = checkType($feed);

        if($ret['type'] != 'skip')
        {
            // New: Creating new records
            if($ret['type'] == 'new')
            {
                createAll($feed, $variation_arr, $feedPos, $keywords_arr, $finalUrl);

            }



            // Activate: Activating paused
            if($ret['type'] == 'activate')
            {
                $data = $ret['data'];

                $adGroupData = getAdgroupByProductId($feed[0]);
                if ($adGroupData) {
                    resumeAdGroup($adGroupData->adgroup_id);
                    saveInTable(DB_ADGROUPS, ["status" => "Active"], ["id" => $adGroupData->id]);

                    $adData = getAdsByProductId($feed[0]);
                    if ($adData) {
                        foreach ($adData as $dd) {
                            resumeAd($adGroupData->adgroup_id, $dd->ad_id);
                            saveInTable(DB_ADS, ["status" => "Active"], ["id" => $dd->id]);
                        }
                        log_("Product: '" . $feed[6] . "' Resumed");
                    }
                }


            }


            // Pause: Pausing Adgroup and Ads
            if($ret['type'] == 'pause')
            {
                $data = $ret['data'];

                $adGroupData = getAdgroupByProductId($feed[0]);
                if ($adGroupData) {
                    pauseAdGroup($adGroupData->adgroup_id);
                    saveInTable(DB_ADGROUPS, ["status" => "Not Active"], ["id" => $adGroupData->id]);

                    $adData = getAdsByProductId($feed[0]);
                    if ($adData) {
                        foreach ($adData as $dd) {
                            pauseAd($adGroupData->adgroup_id, $dd->ad_id);
                            saveInTable(DB_ADS, ["status" => "Not Active"], ["id" => $dd->id]);
                        }
                        log_("Product: '" . $feed[6] . "' Resumed");
                    }
                }


            }

            // Name_Change: Pausing Old and Creating new Record for Name Change
            if($ret['type'] == 'name_change')
            {
                // Pausing Adgroups
                $adGroupData = getAdgroupByProductId($feed[0]);
                if($adGroupData)
                {
                    pauseAdGroup($adGroupData->adgroup_id);
                    saveInTable(DB_ADGROUPS, ["status"=>"Not Active", "last"=>"false"], ["id"=>$adGroupData->id]);

                    $adData = getAdsByProductId($feed[0]);
                    if($adData)
                    {
                        foreach ($adData as $dd)
                        {
                            pauseAd($adGroupData->adgroup_id, $dd->ad_id);
                            saveInTable(DB_ADS, ["status"=>"Not Active", "last"=>"false"], ["id"=>$dd->id]);
                        }
                        log_("Product: '".$feed[6]."' Paused");
                    }
                }
                // Create New
                createAll($feed, $variation_arr, $feedPos, $keywords_arr, $finalUrl);

            }


            // Keyword_Change: Replacing the keywords
            if($ret['type'] == 'keyword_change')
            {
                $adGroupData = getAdgroupByProductId($feed[0]);
                if($adGroupData)
                {
                    if($feed[16] == "Active" && $adGroupData->status != "Active")
                    {
                        resumeAdGroup($adGroupData->adgroup_id);
                        saveInTable(DB_ADGROUPS, ["status" => "Active"], ["id" => $adGroupData->id]);

                        $adData = getAdsByProductId($feed[0]);
                        if ($adData) {
                            foreach ($adData as $dd) {
                                resumeAd($adGroupData->adgroup_id, $dd->ad_id);
                                saveInTable(DB_ADS, ["status" => "Active"], ["id" => $dd->id]);
                            }
                            log_("Product: '" . $feed[6] . "' Resumed");
                        }
                    }
                    // Removing keywords
                    $keywords = getKeywordsByProductId($feed[0]);
                    foreach ($keywords as $keyword)
                    {
                        removeKeyword($adGroupData->adgroup_id, $keyword->keyword_id);
                        Database::table(DB_KEYWORDS)->find($keyword->id)->delete();
                    }

                    // Adding Keywords

                    $retn = createKeywords($adGroupData->adgroup_id, $keywords_arr, $finalUrl, KEYWORDS_BID);
                    foreach ($retn as $kw)
                    {
                        saveInTable(
                            DB_KEYWORDS,
                            [
                                'keyword_id'    =>  $kw['id'],
                                'keyword'       =>  $kw['text'],
                                'adgroup_id'    =>  $adGroupData->adgroup_id,
                                'campaign_id'   =>  $campaign_id,
                                'product_id'    =>  $feed[0],
                                'status'        =>  $feed[16]
                            ]
                        );
                    }

                    // logging
                    log_("Keywords in Product: '".$feed[6]."' updated to: '".implode(", ", $keywords_arr)."'");
                }

            }



            // Other_Change: Pausing Ad and Creating new
            if($ret['type'] == 'other_change')
            {
                $adGroupData = getAdgroupByProductId($feed[0]);
                if($adGroupData)
                {
                    if($feed[16] == "Active" && $adGroupData->status != "Active")
                    {
                        resumeAdGroup($adGroupData->adgroup_id);
                        saveInTable(DB_ADGROUPS, ["status" => "Active"], ["id" => $adGroupData->id]);
                    }

                    // Pausing Ads
                    $adsData = getAdsByProductId($feed[0]);
                    foreach ($adsData as $adData)
                    {
                        pauseAd($adGroupData->adgroup_id, $adData->ad_id);
                        saveInTable(DB_ADS, ["status"=>"Not Active", "last"=>"false"], ["id"=>$adData->id]);
                        log_("Ad: '".$adData->headline1."' Paused");
                    }

                    // Creating Ads
                    $headlines = makeAds($feed, $variation_arr, $adGroupData->adgroup_id, $finalUrl);

                    // Logging
                    log_("Ads: '".implode(", ", $headlines)."' Created");
                }


            }

        }

        // Saving to product table in database
        saveProduct($feed);

        $count++;
    }
    echo "\n";


}


/*
 * Function saves in product data
 */
function saveProduct($feed)
{
    global $campaign_id;

    saveInTable(
        DB_PRODUCTS,
        [
            'product_id'    =>  $feed[0],
            'product_name'  =>  $feed[6],
            'description'   =>  $feed[5],
            'price'         =>  $feed[2],
            'discount'      =>  $feed[10],
            'status'        =>  $feed[16],
            'url'           =>  $feed[14],
            'keywords'      =>  $feed[12],
            'processed'     =>  'true',
            'campaign_id'   =>  $campaign_id
        ],
        ['product_id'   =>  $feed[0]]
    );
}


/*
 * Function creates keyword
 * @params: float $adGroupId, array $keywordsArr, string $finalUrl, integer $bid
 * @return: array $keywordIds
 */

function createKeywords($adgroupId, $keywordsArr, $finalUrl, $bid)
{
    global $session;
    $ret = AddKeywords::run(new AdWordsServices(), $session, $adgroupId, $keywordsArr, $finalUrl, $bid);
    return $ret;
}


/*
 *  Function creates ad in bulk
 *  Params:  Integer $adGroupId, Array $ads
 *  Returns: array
 */
function createAds($adGroupId, $ads)
{
    global $session;
    $ad_data = AddAds::run(new AdWordsServices(), $session, $adGroupId, $ads);
    return $ad_data;
}


/*
 * Function retrieves adgroup data by product_id from adgroups database
 * @param integer $product_id
 * @return stdObj adgroup
 */
function getAdgroupByProductId($product_id, $activeOnly=false)
{
    global $campaign_id;
    if($activeOnly) $row = Database::table(DB_ADGROUPS)->where('product_id', "=", $product_id)->andWhere('campaign_id', '=', $campaign_id)->andWhere('status', '=', 'Active')->andWhere('last', '=', 'true')->find();
    else $row = Database::table(DB_ADGROUPS)->where('product_id', "=", $product_id)->andWhere('campaign_id', '=', $campaign_id)->andWhere('last', '=', 'true')->find();
    if(isset($row->id)) return $row;
}


/*
 * Function retrieves ad data by product_id from ads database
 * @param integer $product_id
 * @return array of stdObj ad row
 */
function getAdsByProductId($product_id, $activeOnly=false)
{
    global $campaign_id;
    if($activeOnly) $table = Database::table(DB_ADS)->where('product_id', "=", $product_id)->andwhere('last', '=', 'true')->andWhere('campaign_id', '=', $campaign_id)->andWhere('status', '=', 'Active')->findAll();
    else $table = Database::table(DB_ADS)->where('product_id', "=", $product_id)->andwhere('last', '=', 'true')->andWhere('campaign_id', '=', $campaign_id)->findAll();
    if(count($table) > 0) return $table;
}



/*
 * Function retrieves keywords by product_id from ads database
 * @param integer $product_id
 * @return array of stdObj keyword row
 */
function getKeywordsByProductId($product_id)
{
    global $campaign_id;
    $table = Database::table(DB_KEYWORDS)->where('product_id', "=", $product_id)->andWhere('campaign_id', '=', $campaign_id)->findAll();
    if(count($table) > 0) return $table;
}


/*
 * Function Resumes a paused adgroup
 * @param float $adGroupId
 */
function resumeAdGroup($adGroupId)
{
    global $session;
    ResumeAdGroup::run(new AdWordsServices(), $session, $adGroupId);
}


/*
 * Function Resumes a paused ad
 * @param float $adGroupId
 * @param float $adId
 */
function resumeAd($adGroupId, $adId)
{
    global $session;
    ResumeAd::run(new AdWordsServices(), $session, $adGroupId, $adId);
}


/*
 * Function Pause a paused ad
 * @param float $adGroupId
 * @param float $adId
 */
function pauseAd($adGroupId, $adId)
{
    global $session;
    PauseAd::run(new AdWordsServices(), $session, $adGroupId, $adId);
}


/*
 * Function Removes keyword
 * @param float $adGroupId
 * @param float $keywordId
 */
function removeKeyword($adGroupId, $keywordId)
{
    global $session;
    RemoveKeyword::run(new AdWordsServices(), $session, $adGroupId, $keywordId);
}


/*
 * Function updates keyword in adwords dashboard
 * @params: float $adGroupId, float $keywordId, string $finalUrl
 */
function updateKeyword($adGroupId, $keywordId, $finalUrl)
{
    global $session;
    UpdateKeyword::run(new AdWordsServices(), $session, $adGroupId, $keywordId, $finalUrl);
}


/*
 * Function creates new records, adgroups, ad, keywords
 */
function createAll($feed, $variation_arr, $feedPos, $keywords_arr, $finalUrl)
{
    if(eligibleProduct($feed, $feedPos))
    {
        global $campaign_id;

        // Create AdGroup
        $adGroupName = $feed[6]." (".$feed[0].")";
        $adGroupId = createAdGroup($campaign_id, $adGroupName, BID, $feed[16]);
        saveInTable(
            DB_ADGROUPS,
            [
                'adgroup_id'    =>  $adGroupId,
                'adgroup_name'  =>  $adGroupName,
                'product_id'    =>  $feed[0],
                'campaign_id'   =>  $campaign_id,
                'status'        =>  $feed[16],
                'last'          =>  'true'
            ]
        );


        // Compile ads per product
        makeAds($feed, $variation_arr, $adGroupId, $finalUrl);


        // Create Keywords
        if(eligibleKeywords($feed, $feedPos))
        {
            $ret = createKeywords($adGroupId, $keywords_arr, $finalUrl, KEYWORDS_BID);
            foreach ($ret as $kw)
            {
                saveInTable(
                    DB_KEYWORDS,
                    [
                        'keyword_id'    =>  $kw['id'],
                        'keyword'       =>  $kw['text'],
                        'adgroup_id'    =>  $adGroupId,
                        'campaign_id'   =>  $campaign_id,
                        'product_id'    =>  $feed[0],
                        'status'        =>  $feed[16]
                    ]
                );
            }
        }

        // Logging
        log_("Create Product: '".$feed[6]."' With ".count($variation_arr)." Ads Variations and Keywords (".implode(", ", $keywords_arr).")");
    }

}


/*
 * Function Creates products with ad variations
 * @params: array $feed, array $variation_arr, double $adGroupId
 */
function makeAds($feed, $variation_arr, $adGroupId, $finalUrl)
{
    global  $campaign_id;
    $ads = [];

    foreach ($variation_arr as $var)
    {
        $productNameLimit = 30 - (strlen($var['headline1']) - 15);
        $productName = substr($feed[6], 0, $productNameLimit);
        $headline1 = str_replace("{{productName}}", $productName, $var['headline1']);
        $headline1 = str_replace("{{productPrice}}", str_replace(" EUR", "", $feed[2]), $headline1);
        $headline1 = str_replace("{{productDiscountInPercent}}", $feed[10], $headline1);
        $headline2 = str_replace("{{productName}}", $productName, $var['headline2']);
        $headline2 = str_replace("{{productPrice}}", str_replace(" EUR", "", $feed[2]), $headline2);
        $headline2 = str_replace("{{productDiscountInPercent}}", $feed[10], $headline2);

        $ads[] = new Ad($feed[0], $headline1, $headline2, $feed[5], array($finalUrl), $feed[16], null, null);
    }

    // Create Ads
    $ads_ret = [];
    $ad_ids = createAds($adGroupId, $ads);
    for($j=0; $j<count($variation_arr); $j++)
    {
        saveInTable(
            DB_ADS,
            [
                'product_id'    =>  $feed[0],
                'ad_id'         =>  $ad_ids[$j],
                'adgroup_id'    =>  $adGroupId,
                'campaign_id'   =>  $campaign_id,
                'headline1'     =>  $ads[$j]->headline1,
                'headline2'     =>  $ads[$j]->headline2,
                'description'   =>  $feed[5],
                'final_url'     =>  $finalUrl,
                'status'        =>  $feed[16],
                'last'          =>  'true'
            ]
        );

        $ads_ret[] = $ads[$j]->headline1;
    }

    return $ads_ret;

}


/*
 * Function prepares database for next run
 */
function prepare4NextRun()
{
    $table = Database::table(DB_PRODUCTS)->findAll();
    foreach ($table as $row)
    {
        $row = Database::table(DB_PRODUCTS)->find($row->id); //Edit row with ID 1
        $row->processed = 'false';
        $row->save();
    }

}


/*
 * Function to check if its a new product, name changed, to be paused, keyword change, others changed, or already proceeds product
 * @params array $feed
 */
function checkType($feed)
{
    $row = Database::table(DB_PRODUCTS)->where('product_id', '=', $feed[0])->find();
    if(isset($row->id))
    {
        // For Processed, will be skipped
        if($row->processed == 'true') return array('type'=>'skip', 'data'=>null);

        // For Name Change
        if($feed[6] != $row->product_name) return array('type'=>'name_change', 'data'=>$row);

        // For Others Change
        if($feed[2] != $row->price || $feed[5] != $row->description || $feed[10] != $row->discount || $feed[14] != $row->url) return array('type'=>'other_change', 'data'=>$row);

        // For Keywords Change
        if($feed[12] != $row->keywords) return array('type'=>'keyword_change', 'data'=>$row);

        // For activate
        if($feed[16] == 'Active' && $row->status != 'Active') return array('type'=>'activate', 'data'=>$row);

        // For Pause
        if($feed[16] != 'Active' && $row->status == 'Active') return array('type'=>'pause', 'data'=>$row);
    }
    else
    {
        // For new
        return array('type'=>'new', 'data'=>null);
    }
}



/*
 * Function retrieves data from products database that hasnt been proceeds, i.e products that did not occur in feed, also called GONE
 * @return: array of stdObj
 */
function getGone()
{
    global $campaign_id;
    $table = Database::table(DB_PRODUCTS)->where('processed', '=', 'false')->andWhere('campaign_id', '=', $campaign_id)->findAll();
    return $table;
}


/*
 * Function Pauses gone
 * @param array of stdObj $gones
 */
function pauseGones($gones)
{
    foreach ($gones as $gone)
    {
        // Retrieving Adgroup data
        $adGroupData = getAdgroupByProductId($gone->product_id);
        if($adGroupData)
        {
            pauseAdGroup($adGroupData->adgroup_id);
            saveInTable(DB_ADGROUPS, ["status" => "Not Active"], ["id" => $adGroupData->id]);

            $adData = getAdsByProductId($gone->product_id);
            if ($adData) {
                foreach ($adData as $dd) {
                    pauseAd($adGroupData->adgroup_id, $dd->ad_id);
                    saveInTable(DB_ADS, ["status" => "Not Active"], ["id" => $dd->id]);
                }
                saveInTable(DB_PRODUCTS, ["status" => "Not Active"], ["product_id" => $gone->product_id]);
                log_("Product: '" . $gone->product_name . "' Pause; No longer Exist in Feed");
            }
        }
    }
}



////////////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////     Lazer Functions     /////////////////////////////////
////////////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////////////


/*
 * Function creates table if not exist
 * @param string $tableName
 * @throws LazerException
 */
function initTables()
{
    // Create Tables
    Database::create(DB_PRODUCTS,
        [
            'id'            =>  'integer',
            'product_id'    =>  'string',
            'product_name'  =>  'string',
            'description'   =>  'string',
            'price'         =>  'string',
            'discount'      =>  'string',
            'status'        =>  'string',
            'url'           =>  'string',
            'keywords'      =>  'string',
            'processed'     =>  'string',
            'campaign_id'   =>  'string'
        ]
    );

    Database::create(DB_ADS,
        [
            'id'            =>  'integer',
            'product_id'    =>  'string',
            'ad_id'         =>  'string',
            'adgroup_id'    =>  'string',
            'campaign_id'   =>  'string',
            'headline1'     =>  'string',
            'headline2'     =>  'string',
            'description'   =>  'string',
            'final_url'     =>  'string',
            'status'        =>  'string',
            'last'          =>  'string'
        ]
    );

    Database::create(DB_ADGROUPS,
        [
            'id'            =>  'integer',
            'adgroup_id'    =>  'string',
            'adgroup_name'  =>  'string',
            'product_id'    =>  'string',
            'campaign_id'   =>  'string',
            'status'        =>  'string',
            'last'          =>  'string'
        ]
    );

    Database::create(DB_KEYWORDS,
        [
            'id'            =>  'integer',
            'keyword_id'    =>  'string',
            'keyword'       =>  'string',
            'adgroup_id'    =>  'string',
            'product_id'    =>  'string',
            'campaign_id'   =>  'string',
            'status'        =>  'string'
        ]
    );

    Database::create(DB_CAMPAIGNS,
        [
            'id'            =>  'integer',
            'campaign_id'   =>  'string',
            'campaign_name' =>  'string'
        ]
    );

    // Relate Tables
    Relation::table('AdGroups')->belongsTo('Campaigns')->localKey('campaign_id')->foreignKey('campaign_id')->setRelation();
    Relation::table('Ads')->belongsTo('Campaigns')->localKey('campaign_id')->foreignKey('campaign_id')->setRelation();
    Relation::table('Ads')->belongsTo('Products')->localKey('product_id')->foreignKey('product_id')->setRelation();
    Relation::table('AdGroups')->belongsTo('Products')->localKey('product_id')->foreignKey('product_id')->setRelation();
    Relation::table('Ads')->belongsTo('AdGroups')->localKey('adgroup_id')->foreignKey('adgroup_id')->setRelation();
    Relation::table('Keywords')->belongsTo('AdGroups')->localKey('adgroup_id')->foreignKey('adgroup_id')->setRelation();


}


/*
 * Function Inserts and updates record into database
 * @param string @table Table name
 * @param array @data Assoc array of values where key is field name
 *
 */
function saveInTable($table, $data, $unique=null)
{
    if($unique)  $row0 = Database::table($table)->where(key($unique), '=', reset($unique))->find();

    if(isset($row0->id)) $row = Database::table($table)->find($row0->id); // Handle update
    else $row = Database::table($table); // Handle insert

    foreach ($data as $key=>$value)
    {
        $row->$key = "$value";
    }
    $row->save();
}



