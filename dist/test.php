<?php

include '../functions.php';


updateCampaigns();

$campaignName = str_replace("_", " ", $argv[1]);


//Fetching campaign id by campaign name, will create if not exist
$campaign_id =  getCampaignIdByName($campaignName);



// //$t = getAds("53030040155");
// // $t = searchAdGroupFromServer($campaign_id, "53030040155");
// $t = searchAdGroupByName($campaign_id, "After Bite Stift Kids (AT-P2946112-20G)");

// var_dump($t);