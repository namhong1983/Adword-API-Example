<?php
/**
 * Created by PhpStorm.
 * User: Joseph Lukan
 * Date: 9/10/2017
 * Time: 11:56 AM
 */


// Allows mac detect line_endings in fgets methods
ini_set("auto_detect_line_endings", true);

// Setting currency format
setlocale(LC_MONETARY,"en_US");



require "../vendor/autoload.php";
include "constants.php";
include "../dist/variation.php";
include "../classes/AddAdGroup.php";
include "../classes/GetCampaigns.php";
include "../classes/AddAd.php";
include "../classes/GetAdGroupsByCampaign.php";
include "../classes/GetAds.php";
include "../classes/PauseAd.php";
include "../classes/RemoveAd.php";
include "../classes/AddCampaign.php";




use Google\AdsApi\Common\OAuth2TokenBuilder;
use Google\AdsApi\AdWords\AdWordsSessionBuilder;
use Google\AdsApi\AdWords\AdWordsServices;



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
$campaign_id = "";
$campaigns = [];
updateCampaigns();


//////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////


/*
 * Function to convert csv to array
 * param: $filename
 * return: assoc 2D array of feeds
 */
function feedToArr($fileName)
{
    $file = fopen(FEED_PATH.$fileName, 'a+');
    $cc = 0;
    $result = [];
    while (($line = fgetcsv($file)) !== FALSE) {
        //$line is an array of the csv elements
        if($cc++ > 0)$result[] = $line;
    }
    fclose($file);

    return $result;
}


function removeProductIds($feedArr)
{
    foreach ($feedArr as $feed)
    {
        $ads = existAd($feed[0]);
        foreach ($ads as $ad)
        {
            deleteLineInFile(TEMP_PATH.PRODUCTS_LOCAL_FILE, $ad['line_number']);
        }
    }
    defragment(TEMP_PATH.PRODUCTS_LOCAL_FILE);
}


/*
 *  Function to get campaign is by name
 *  Params: String Name,
 *  Returns: Integer
 */
function getCampaignIdByName($name)
{
    global $campaigns;
    $id = null;
    foreach($campaigns as $campaign)
    {
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
    writeToFile(CAMPAIGNS_LOCAL_FILE, "$id||$name\n");
    log_("Create Campaign: $name");
    return $ret;
}


function emptyFile($fileName)
{
    unlink($fileName);
    $sh = fopen($fileName, 'a+');
    fclose($sh);
}

/*
 * Function used to update the currently available campaigns
 */
function updateCampaigns()
{
    global $campaigns;
    $campaigns = getCampaigns();
    if(file_exists(TEMP_PATH.CAMPAIGNS_LOCAL_FILE)) emptyFile(TEMP_PATH.CAMPAIGNS_LOCAL_FILE);
    foreach ($campaigns as $campaign)
    {
        $name = $campaign['name'];
        $id = $campaign['id'];
        writeToFile(CAMPAIGNS_LOCAL_FILE, "$id||$name\n");
    }
    return TRUE;
}


function updateAdGroups($campaigns)
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
                writeToFile(ADGROUPS_LOCAL_FILE, "$adGroupId||$adGroupName\n");
            }
        }
    }
    return $results;
}


function updateAds($adGroups)
{
    if(file_exists(TEMP_PATH.ADS_LOCAL_FILE)) emptyFile(TEMP_PATH.ADS_LOCAL_FILE);
    $results = [];
    foreach ($adGroups as $adGroup)
    {
        $ads = getAds($adGroup['id']);
        if(count($ads) > 0)
        {
            foreach ($ads as $ad)
            {
                $id = $ad['id'];
                $headlinePart1 = $ad['headlinePart1'];
                $adGroupId = $ad['adGroupId'];
                $results[] = array('id'=>$id, 'headlinPart1'=>$headlinePart1);
                writeToFile(ADS_LOCAL_FILE, "$id||$headlinePart1||$adGroupId\n");
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



/*
 *  Function creates ad groups into campaign supplied
 *  Params: Integer $campaign_id, String $adGroup_name, Integer $bid
 *  Returns: Integer
 */
function createAdGroup($campaign_id, $adGroup_name, $bid)
{
    global $session;
    global $adwordServices;
    $next_id = 1;
    $iid = existAdGroup($adGroup_name);
    if($iid) $next_id = $iid['last_number']+1;
    $adGroup_name = $adGroup_name."#".$next_id;
    $id = AddAdGroup::run($adwordServices, $session, $campaign_id, $adGroup_name, $bid);
    writeToFile(ADGROUPS_LOCAL_FILE, "$id||$adGroup_name\n");
    log_("Create AdGroup: $adGroup_name");
    return $id;
}

/*
 *  Function creates ad in bulk
 *  Params:  Integer $adGroupId, Array $ads
 *  Returns: array
 */
function createAd($adGroupId, $ad)
{
    global $session;
    global $adwordServices;
    global $campaign_id;
    $ad_data = AddAd::run($adwordServices, $session, $adGroupId, $ad);
    writeToFile(ADS_LOCAL_FILE, $ad_data['id']."||".$ad_data['name']."||".$adGroupId."\n");
    writeToFile(PRODUCTS_LOCAL_FILE, $ad_data['id']."||".$ad->productId."||".$campaign_id."\n");
    $paused = ($ad->status == "active")?"":"Paused";
    log_("Create $paused Ad: ".$ad_data['name']);
    return $ad_data;
}


/*
 * Function to pause an Ad
 * params: adgroup_id and ad_id
 */
function pauseAd($adgroup_id, $ad_id, $ad_name)
{
    global $session;
    PauseAd::run(new AdWordsServices(), $session, $adgroup_id, $ad_id);
    log_("Pause Ad: ".$ad_name);
}


/*
 * Function to make total ads to create from product feeds,
 * params: $feedArr, $variation_arr
 * return: array of ads object
 */
function adsToInsert($feedArr, $variation_arr)
{
    $ads = [];
    foreach ($feedArr as $feed)
    {
        $keywords = explode(";", $feed[8]);
        foreach ($variation_arr as $var)
        {
            $productName = substr($feed[1], 0, 15);
            $headline1 = str_replace("{{productName}}", $productName, $var['headline1']);
            $headline1 = str_replace("{{productPrice}}", number_format($feed[2]), $headline1);
            $headline1 = str_replace("{{productDiscountInPercent}}", $feed[7], $headline1);
            $headline2 = str_replace("{{productName}}", $productName, $var['headline2']);
            $headline2 = str_replace("{{productPrice}}", number_format($feed[2]), $headline2);
            $headline2 = str_replace("{{productDiscountInPercent}}", $feed[7], $headline2);

            //$category = substr($feed[4], 0, 15);
            $category = $feed[4];

            $product_url = $feed[10];
            $is_https = strpos($product_url, "https://");
            $product_url = str_replace("http://", "", $product_url);
            $product_url = str_replace("https://", "", $product_url);
            $url_parts = explode("/", $product_url, 3);

            $final_url = $is_https?"https://".$url_parts[0]:"http://".$url_parts[0];

            $ads[] = new Ad($feed[0], $category, $headline1, $headline2, $feed[3], array($final_url), $feed[12], $url_parts[1], $url_parts[2]);
        }
    }
    return $ads;
}



/*
 * Function take only on ad object then searches if its adgroup exist, is adgroup exists or is full, it creates new one and inserts
 * param: ad (object)
 *
 */
function createAdDyn($campaign_id, $ad)
{
    global $session;

    // First check if the category/adgroup exist
    $adGroupDet = existAdGroup($ad->category);
    if($adGroupDet)
    {
        $lastAdGroupId = $adGroupDet['last_id'];
        $lastNumber = $adGroupDet['last_number'];

        // loop throu to fill up adgroups starting from l
        $i = $lastNumber;

        while ($i > 0)
        {
            $adGroupId = getAdGroupId($ad->category."#".$i);

            // Check if adgroup isnt full
            if(countAdsInAdGroup($adGroupId) < 50)
            {
                createAd($adGroupId, $ad);
                break;
            }
            $i--;
        }
        // If all adGroups are full, then create the adgroup and insert ad
        if($i == 0)
        {
            $adGroupId = createAdGroup($campaign_id, $ad->category, BID);
            createAd($adGroupId, $ad);
        }
    }
    else
    {
        // Create the adGroup and insert ad
        $adGroupId = createAdGroup($campaign_id, $ad->category, BID);
        createAd($adGroupId, $ad);
    }

}

/*
 * Function to remove ads that will be re-created
 * param: $feedArr
 *
 */
function cleanUp($feedArr)
{
    echo "Cleaning Up repeat products ....\n";
    foreach ($feedArr as $feed)
    {
        $existAdArray = existAd($feed[0]);  // check if product/ads exist
        if(count($existAdArray) > 0) removeExistAds($existAdArray);  // remove ads
    }
}

/*
 * Function to compile ads that were not cleaned, to be paused
 * param:
 * return: assoc array of ads (ad_id and adgroup_id)
 */
function residue()
{
    $ids = [];
    $handle = fopen(TEMP_PATH.ADS_LOCAL_FILE, "a+");
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            if(trim($line) != "")
            {
                $line_arr = explode("||", $line);
                $ids[] = array("ad_id"=>trim($line_arr[0]), "ad_h1"=>trim($line_arr[1]), "adgroup_id"=>trim($line_arr[2]));
            }
        }
        fclose($handle);
    } else {
        // error opening the file.
    }
    return $ids;
}


/*
 * Function to pause all the ads from residue function
 * params: $residue
 * return:
 */
function pauseResidues($residueAds)
{
    echo "Pausing Non-repeat Adverts ....\n";
    if(count($residueAds) == 0) return;
    foreach ($residueAds as $ad)
    {
        pauseAd($ad['adgroup_id'], $ad['ad_id'], $ad['ad_h1']);
    }
}




function countAdsInAdGroup($adGroupId)
{
    global $session;
    $ads = GetAds::run(new AdWordsServices(), $session, $adGroupId);
    return count($ads);
}




/*
 *  Function checks if a adgroup exists and return the id or false
 *  Params:  Integer $adgroup
 *  Returns: Array of last_id and last_number
 */
function existAdGroup($findName)
{
    $findName = trim($findName);
    $adGroupId = null;
    $adGroupLastNumber = 0;
    $handle = fopen(TEMP_PATH.ADGROUPS_LOCAL_FILE, "a+");
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            if(trim($line) != "")
            {
                $line_arr = explode("||", $line);
                $id = trim($line_arr[0]);
                $nameFull = trim($line_arr[1]);

                $adGroupNameSplit = explode("#", $nameFull);
                $adGroupName = trim($adGroupNameSplit[0]);
                $adGroupNumber = trim($adGroupNameSplit[1]);

                if($findName == $adGroupName)
                {
                    if($adGroupNumber > $adGroupLastNumber) $adGroupLastNumber = $adGroupNumber;
                }
            }

        }

        fclose($handle);
    } else {
        // error opening the file.
    }

    $adGroupId = getAdGroupId($findName."#".$adGroupLastNumber);

    if($adGroupId) return array('last_id'=>$adGroupId, 'last_number'=>$adGroupLastNumber);
    else return FALSE;
}


function getAdGroupId($name)
{
    $name = trim($name);
    $adGroupId = null;
    $handle = fopen(TEMP_PATH.ADGROUPS_LOCAL_FILE, "a+");
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            $line_arr = explode("||", $line);
            $id = trim($line_arr[0]);
            $nameFull = trim($line_arr[1]);


            if($nameFull == $name)
            {
                $adGroupId = $id;
                break;
            }
        }

        fclose($handle);
    } else {
        // error opening the file.
    }
    return $adGroupId;

}


function getAdGroups($campaign_id)
{
    global $session;
    return GetAdGroupsByCampaign::run(new AdWordsServices(), $session, $campaign_id);
}


function getAds($adGroupId)
{
    global $session;
    return GetAds::run(new AdWordsServices(), $session, $adGroupId);
}


/*
 *  Function find all occurences of the product id
 *  Params:  Integer $findProdtuctId
 *  Returns: Array of ad_id, line_number(In file), and adgroup_id
 */
function existAd($findProdtuctId)
{
    $ads = adIdsFromProductId($findProdtuctId);
    $occur = [];
    foreach($ads as $ad)
    {
        $productLineNumber = $ad['line_number'];
        $handle = fopen(TEMP_PATH.ADS_LOCAL_FILE, "a+");
        $cc = 1;
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if(trim($line) != "")
                {
                    $line_arr = explode("||", $line);
                    $id = $line_arr[0];
                    $adName = trim($line_arr[1]);
                    $adGroupId = trim($line_arr[2]);

                    if($ad['ad_id'] == $id)
                    {
                        $occur[] = array("ad_id"=>$id, "ad_name"=>$adName, "line_number"=>$cc, "line_number_product"=>$productLineNumber, "adgroup_id"=>$adGroupId);
                    }
                }

                $cc++;
            }

            fclose($handle);
        } else {
            // error opening the file.
        }
    }


    return $occur;
}


function adIdsFromProductId($findProdtuctId)
{
    global $campaign_id;
    $handle = fopen(TEMP_PATH.PRODUCTS_LOCAL_FILE, "a+");
    $cc = 1;
    $occur = [];
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            if(trim($line) != "")
            {
                $line_arr = explode("||", $line);
                $adId = $line_arr[0];
                $prodtuctId = trim($line_arr[1]);
                $camp_id = trim($line_arr[2]);

                if(($prodtuctId == $findProdtuctId) && $campaign_id == $camp_id)
                {
                    $occur[] = array("ad_id"=>$adId, "line_number"=>$cc);
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
 *  Function deletes all the ads created by a product
 *  Params:  Array $existAdArray ## must be from existAd function
 *  Returns: boolean
 */
function removeExistAds($existAdArray)
{
    foreach ($existAdArray as $existAd)
    {
        removeAd($existAd['adgroup_id'], $existAd['ad_id'], $existAd['ad_name']);
        deleteLineInFile(TEMP_PATH.ADS_LOCAL_FILE, $existAd['line_number']); //for ads.txt
        deleteLineInFile(TEMP_PATH.PRODUCTS_LOCAL_FILE, $existAd['line_number_product']); //for products.txt
    }
    return true;
}


function removeAd($adGroupId, $adId, $adName)
{
    global $session;
    RemoveAd::run(new AdWordsServices(), $session, $adGroupId, $adId);
    log_("Remove Ad: $adName **to be recreated**");
}


function deleteLineInFile($file, $lineNumber)
{
    $file_out = file($file); // Read the whole file into an array
    //unset($file_out[$lineNumber-1]);
    $file_out[$lineNumber-1] = "\n";
    file_put_contents($file, implode("", $file_out));

}


/*
 * Function removed empty lines from file database
 * param: $file
 */
function defragment($fileName)
{
    $file = file_get_contents($fileName);
    $data = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $file);
    file_put_contents($fileName, $data);
}


function log_($data)
{
    $datetime = date("Y-m-d H:i:s");
    $data = "[$datetime] $data";
    writeToFile(LOG_FILE, $data."\n");
}




//////////////////////////////////////////////////////
//////////////////////////////////////////////////////



