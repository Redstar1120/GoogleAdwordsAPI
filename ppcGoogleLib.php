<?php

require_once __DIR__ . '/../vendor/autoload.php';
use Google\AdsApi\AdWords\AdWordsSessionBuilder;
use Google\AdsApi\Common\OAuth2TokenBuilder;
use Google\AdsApi\AdWords\AdWordsServices;
use Google\AdsApi\AdWords\AdWordsSession;
use Google\AdsApi\AdWords\v201710\cm\CampaignService;
use Google\AdsApi\AdWords\v201710\cm\CampaignOperation;
use Google\AdsApi\AdWords\v201710\cm\AdGroupService;
use Google\AdsApi\AdWords\v201710\cm\AdGroupOperation;
use Google\AdsApi\AdWords\v201710\cm\AdGroup;
use Google\AdsApi\AdWords\v201710\cm\AdGroupCriterionService;
use Google\AdsApi\Common\SoapSettingsBuilder;
use Google\AdsApi\AdWords\v201710\cm\Campaign;
use Google\AdsApi\AdWords\v201710\cm\Operator;
use Google\AdsApi\AdWords\v201710\cm\Selector;
use Google\AdsApi\AdWords\v201710\cm\Paging;
use Google\AdsApi\AdWords\v201710\cm\Predicate;
use Google\AdsApi\AdWords\v201710\cm\DateRange;
use Google\AdsApi\AdWords\v201710\cm\OrderBy;
use Google\AdsApi\AdWords\v201710\cm\SortOrder;
use Google\AdsApi\AdWords\v201710\cm\PredicateOperator;
use Google\AdsApi\AdWords\Reporting\v201710\ReportDefinition;
use Google\AdsApi\AdWords\v201710\cm\ReportDefinitionReportType;
use Google\AdsApi\AdWords\Reporting\v201710\DownloadFormat;
use Google\AdsApi\AdWords\Reporting\v201710\ReportDefinitionDateRangeType;
use Google\AdsApi\AdWords\ReportSettingsBuilder;
use Google\AdsApi\AdWords\Reporting\v201710\ReportDownloader;
use Google\AdsApi\AdWords\v201710\o\AttributeType;
use Google\AdsApi\AdWords\v201710\cm\ReportDefinitionService;
error_reporting(-1); // reports all errors
ini_set("display_errors", "1"); // shows all errors
ini_set("log_errors", 1);
ini_set("error_log", "/tmp/php-error.log");
ini_set('soap.wsdl_cache_enabled',0);
ini_set('soap.wsdl_cache_ttl',0);
chdir(dirname(__FILE__));
DEFINE("ADWORDS_CONFIGURATION_FILE", '/home/calmness/www2/protected/config/adsapi_php.ini');
require_once 'ppcSettings.php';
function GoogleAPITest($campaignID, &$error) {
    chdir(dirname(__FILE__));
    /*
    $res = getCampaignCost($campaignID, '20130101', '20131201', $cost, $error);
    if ($res !== true) {
        $error = 'getCampaignCost error: ' . $error;
        return false;
    }
    */
    $ids = array(PPCSettings::CAMPAIGN_ID);
    foreach ($ids as $id) {
        $status = getCampaignStatus($id, $error);
        if ($status === false) {
            $error = 'getCampaignStatus error: ' . $error . " id=" . $id;
            return false;
        }

        // change status
        $newStatus = ($status === 'ENABLED') ? 'PAUSED' : 'ENABLED';
        if (false === changeCampaignStatus($id, $newStatus, $error)) {
            $error = 'changeCampaignStatus error: ' . $error . " id=" . $id;
            return false;
        }

        // restore status
        if (false === changeCampaignStatus($id, $status, $error)) {
            $error = 'changeCampaignStatus error: ' . $error . " id=" . $id;
            return false;
        }

        $newStatus = getCampaignStatus($id, $error);
        if ($newStatus === false) {
            $error = 'getCampaignStatus error: ' . $error . " id=" . $id;
            return false;
        }
        if ($newStatus != $status) {
            $error = 'getCampaignStatus new status does not match original status: ' . "$newStatus, $status";
            return false;
        }

        // echo "New status is $newStatus \n";
        if (false === getAdgroups($id, $list, $error)) {
            $error = 'getAdgroups error: ' . $error . " id=" . $id;
            return false;
        }
        $list2 = array();
        for ($i = 0; $i < 10 && $i < count($list); $i++) {
            $list2[] = $list[$i]->id;
        }
        echo "Got these adgroups (first 10): " . join(", ", $list2) . "\n";
    }

    return true;
}
/**
 * Warning: this function still doesn't work with v201309
 * Tested 2011-11-30 with v201109
 * @param int $campaignID ID of the campaign
 * @param string $startDay YYYYMMDD formatted day
 * @param string $endDay YYYYone
 * MMDD formatted day
 * @param float &$cost Result
 * @param string &$error [out] Textual information about the operation if it failed
 * @return bool True on success, false on error, $error is filled in this case
 */
function getCampaignCost($campaignID, $startDay, $endDay, &$cost, &$error)
{
    global $GOOGLE_VERSION;

    $error = "After switching to Google version 201309 this function is not implemented";
    // Log SOAP XML request and response.
    try {
        initCredentials($session);
        $adWordsServices = new AdWordsServices();

        //$user->LogDefaults();
    } catch (Exception $e) {

        $error = 'Can not create AdWordsUser: ' . $e;
        return false;
    }
    // Get the CampaignService.
   $campaignService = $adWordsServices->get($session, CampaignService::class);
    $selector = new Selector();
    $selector->setFields(['Id','Status']);
    $selector->setFields(['CampaignId', 'AdGroupId', 'Id', 'Criteria',
        'CriteriaType', 'Impressions', 'Clicks', 'Cost']);
//    $reportDefinitionService =
//        $adWordsServices->get($session, ReportDefinitionService::class);
//    $reportType = ReportDefinitionReportType::CAMPAIGN_PERFORMANCE_REPORT;
//    $reportDefinitionFields =
//        $reportDefinitionService->getReportFields($reportType);
    // Create selector.
    // Cost is a shortcut for the CampaignStats.Cost
    $pre1 = new Predicate('Id', 'IN', array($campaignID));
    $pre2 = new Predicate('Status', 'IN', array('ENABLED', 'PAUSED'));
    $selector->setPredicates([$pre1,$pre2]);
    // Date
    $dateRange = new DateRange();
    $dateRange->setMin($startDay);
    $dateRange->setMax($endDay);
    $selector->setDateRange($dateRange);

    try {
        $result = $campaignService->get($selector);
    } catch (SoapFault $fault) {

        $error = "SOAP error: " . $fault->faultstring;
        return false;
    }

    if (!count($result->getEntries())) {
        $error = "No entries of the response";
        return false;
    }

    $campaign = $result->getEntries()[0];
    ///should fix this ....!
    if ($campaign->getBudget() && $campaign->getBudget()->getAmount()) {
        $cost = $campaign->getCampaignStats()->getCost()->getMicroAmount() / 1000000;
    } else {
        $error = "Result is malformed";
        return false;
    }

    return true;
}


function resumeCampaign($campaignID, &$error)
{
    return changeCampaignStatus($campaignID, 'ENABLED', $error);
}


function pauseCampaign($campaignID, &$error)
{
    return changeCampaignStatus($campaignID, 'PAUSED', $error);
}
/**
 * tested 2011-11-30 v201109
 * @access private
 * @see resumeCampaign() and pauseCampaign()
 * @param int $campaignID ID of the campaign
 * @param string $newStatus must be either 'ENABLED' or 'PAUSED'
 * @param string &$error [out] Textual information about the operation if it failed
 * @return bool True on success, false on error, $error is filled in this case
 */
function changeCampaignStatus($campaignID, $newStatus, &$error)
{
    $operations = [];
    // Log SOAP XML request and response.
    try {
        initCredentials($session);
        $adWordsServices = new AdWordsServices();
    } catch (Exception $e) {

        $error = 'Can`t find required  authenticated user';
        return false;
    }
    $campaignService = $adWordsServices->get($session, CampaignService::class);
    $operation = new CampaignOperation();
    $campaign = new Campaign();
    $operation->setOperand($campaign);
    $operation->setOperator(Operator::SET);
    $operations[] = $operation;
    $campaign->setId($campaignID);
    $campaign->setStatus($newStatus);
    try{
        $result = $campaignService->mutate($operations);
    } catch (SoapFault $fault) {
        $error = "SOAP error: " . $fault->faultstring;
        return false;
    }
    // see result
    $status = $result->getValue()[0]->getStatus();
    if ($result && $result && is_array($result->getValue()) &&
        $result->getValue() && isset($status)) {
        return $result->getValue()[0]->getStatus() == $newStatus;
    }
    return false;
}


/**
 * Tested 2011-11-30 v201109
 * @param int $campaignID ID of the campaign
 * @param string &$error [out] Textual information about the operation if it failed
 * @return string status, FALSE on error, $error is filled in this case
 */
function getCampaignStatus($campaignID, &$error)
{
    // Log SOAP XML request and response.
    try {
        initCredentials($session);
        $adWordsServices = new AdWordsServices();
        //$user->LogDefaults();
    } catch (Exception $e) {
        $error = 'Can not create AdWordsUser';
        return false;
    }
    //$user->LogAll();
    // Get the CampaignService.
    $campaignService = $adWordsServices->get($session, CampaignService::class);
    // Create selector.
    $selector = new Selector();
    $selector->setFields(['Id','Status']);
    $pre1 = new Predicate('Id', 'IN', array($campaignID));
    $selector->setPredicates([$pre1]);

    try {
        $result = $campaignService->get($selector);
    } catch (SoapFault $fault) {
        //print_r($fault);
        $error = "SOAP error: " . $fault->faultstring;
        return false;
    }

    if (!count($result->getEntries())) {
        $error = "No entries of the response";
        return false;
    }
    $campaign = $result->getEntries()[0];
    $campaignStatus = $campaign->getStatus();
    if (isset($campaignStatus)) {
        return $campaign->getStatus();
    } else {
        $error = "Result is malformed";
        return false;
    }
}


/**
 * @param string &$error [out] Textual information about the operation if it failed
 * @return bool True on success, false on error, $error is filled in this case
 * @todo !!!test!!!
 */
function deleteAdGroup($adgroupID, &$error)
{
    // Log SOAP XML request and response.
    try {
        initCredentials($session);
        $adWordsServices = new AdWordsServices();
        //$user->LogDefaults();
    } catch (Exception $e) {
        $error = 'Can not create AdWordsUser';
        return false;
    }
    //$user->LogAll();
    // Get the CampaignService.
    $adGroupService = $adWordsServices->get($session, AdGroupService::class);
    $operation = new AdGroupOperation(); //new CampaignOperation();
    $operation->setOperator(Operator::SET); // should we use 'REMOVE' instead?
    $adGroup = new AdGroup();
    $adGroup->setId($adgroupID);
    $adGroup->setStatus('REMOVED');
    $operation->setOperand($adGroup);
    try {
        $result = $adGroupService->mutate(array($operation));

    } catch (SoapFault $fault) {
        $error = "SOAP error: " . $fault->faultstring;
        return false;
    }

    // see result
    //print_r($result);
    $status = $result->getValue()[0]->getStatus();
    if ($result && $result->getValue() && is_array($result->getValue()) &&
        $result->getValue()[0] && isset($status)) {
        return $result->getValue()[0]->getStatus()== 'REMOVED';
    }

    return false;
}


function doGoogleLog($logmessage) {
    global $GOOGLE_LOG_FILE;
    if ($GOOGLE_LOG_FILE != '') {
        $fh = @fopen($GOOGLE_LOG_FILE, "a+");
        if ($fh !== false) {
            $t = date("Y-m-d") . " " . $logmessage . "\n";
            fwrite($fh, $t);
            fclose($fh);
        }
    }
}


function syncAdwordsStatusesLogged($dbh, $justTheseItems, &$result, &$error, $logmessage)
{
    doGoogleLog($logmessage);
    $r = syncAdwordsStatuses($dbh, $justTheseItems, $result, $error);
    if (!$r) {
        doGoogleLog("Can't sync Google status");
    }
    return $r;
}


/**
 * tested 2011-11-30 v 201109
 * @param array $justTheseItems If not false, then it must be an array of ppcCategory.id values of the records
 *      that need to be requested / updated.
 *      This array may be needed because the adwords API is not free and therefore we need to limit our request.
 * @param string &$result [out] Textual information about the operation if it is successful
 * @param string &$error [out] Textual information about the operation if it failed
 * @return bool True on success $result is filled in this case, false on error, $error is filled in this case
 */
function syncAdwordsStatuses($dbh, $justTheseItems, &$result, &$error)
{
    $result = '';
    $idToAdw = array();
    $adwToId = array();
    $statuses = array();
    $names = array();
    $toUpdate = array();
    $adGroupIds = array();

    // Get adGroup list from the db
    $where = '';
    if ($justTheseItems && is_array($justTheseItems)) {
        $where = " AND id IN (" . join(",", $justTheseItems) . ")";
    }
    $q = "SELECT id, state, adgroupID, catName FROM ppcCategory"
        . " WHERE serviceID = 1" . $where
        . " ORDER BY id";

    $r = pdoQuery($dbh, $q, array(), $error);
    if ($r === false) {
        $error = __LINE__ . " SQL error: " . $error;
        return false;
    }
    while (list($id, $state, $adgroupID, $catName) = $r->fetch(PDO::FETCH_NUM)) {
        $idToAdw[$id] = $adgroupID;
        $adwToId[$adgroupID] = $id;
        $statuses[$id] = $state;
        $names[$id] = $catName;
        $adGroupIds[] = $adgroupID;
    }
    $r = null;

    if (!count($idToAdw)) {
        $result = "There are no categories in the database";
        return true;
    }

    // Log SOAP XML request and response.
    try {

        initCredentials($session);
        $adWordsServices = new AdWordsServices();
        //$user->LogDefaults();
    } catch (Exception $e) {
        $error = 'Can not create AdWordsUser';
        return false;
    }
    $adGroupService = $adWordsServices->get($session, AdGroupService::class);
    // Create selector.
    $selector = new Selector();
    $selector->setFields(array('Id', 'Status')); // 'Name',
    $selector->setOrdering([new OrderBy('Id', SortOrder::ASCENDING)]);
    // $selector->paging->numberResults = 10000;
    $selector->setPaging(new Paging(0, 10000));// AdWordsConstants::RECOMMENDED_PAGE_SIZE
    // Create predicates.
    $selector->setPredicates([
        new Predicate('Id', PredicateOperator::IN, $adGroupIds)
    ]);
    try {
        $result = $adGroupService->get($selector);
    } catch (Exception $e) {
        $error = __LINE__ . " SOAP error: " . $e->getMessage();
        return false;
    }
    $entries = $result->getEntries();
    if (!(isset($entries) && count($entries))) {
        $error = __LINE__ . " No matching adgroups got from Google";
        return false;
    }

    // See the google statuses and update the list with updateAdGroupList
    $operations = array();
    $i = 0;
    foreach ($result->getEntries() as $x) {
        $adgroupID = $x->getId();
        if ($x->getStatus() == 'DELETED') { continue; }
        $status = ($x->getStatus() == 'ENABLED'); // convert to bool
        $status += 0; // convert to int
        // Get database ID of the adGroup:
        $id = $adwToId[$adgroupID];
        // Get our status
        $dbStatus = $statuses[$id];
        $dbStatus += 0; // convert to int
        if ($dbStatus != $status) {
            $toUpdate[$i++] = $id;
            $operation = new AdGroupOperation();
            $operation->setOperator(Operator::SET);
            $adGroup = new AdGroup();
            $adGroup->setId($adgroupID);
            $adGroup->setStatus(($dbStatus ? 'ENABLED' : 'PAUSED'));
            $operation->setOperand($adGroup);
            $operations[] = $operation;
        }
    }

    if (!$i) {
        $result = "All categories in Google have the same status as in the database, there is nothing to update.";
        return true;
    }

    // We've got something to update
    try {
        $result = $adGroupService->mutate($operations);
    } catch (SoapFault $fault) {
        $error = __LINE__ . " SOAP error: " . $fault->faultstring;
        return false;
    }

    if (!($result && $result->getValue() && is_array($result->getValue()) &&
        (count($result->getValue()) == $i))) {
        $error = __LINE__ . " Number of adGroups updated does not match number of adgroups we tried to update";
        return false;
    }

    $result = "Adgroup statuses updated OK for the following groups:\n\n";
    foreach($toUpdate as $id) {
        $statuses[$id] += 0;
        $result .= '"' . $names[$id] . "\" set to " . ($statuses[$id] ? 'Enabled' : 'Paused') . "\n";
        doGoogleLog($result);
    }
    return true;
}
class GoogleKeywords {

    static public function formatDate($time) {
        // In 2009 API all input dates are in YYYYMMDD format
        return date('Ymd', $time);
    }

    /**
     * Exceptions for keyword conversions between Google Adword keywords
     * and our internal keyword representation.
     */
    static public $exceptions = array(
        '"english-ivy"' => 'google-english-ivy2',
        '"flowering vinca"' => 'google-flowering-vinca',
        '"groundcover"' => 'google-groundcover',
        '"ground cover plants"' => 'google-ground-cover-plants2',
        '"ground covers"' => 'google-ground-covers',
        '"groundcovers"' => 'google-groundcovers',
        '"ice plant"' => 'google-ice-plant',
        '"lariope"' => 'google-lariope',
        '"liriope"' => 'google-liriope1',
        '"lirope"' => 'google-liriope',
        '"pachysandra"' => 'google-pachysandra1',
        '"periwinkle flowers"' => 'google-periwinkle-flowers',
        '"periwinkle vinca"' => 'google-periwinkle-vinca',
        '"trailing vinca"' => 'google-trailing-vinca',
        '"varigated liriope"' => 'google-varigated-liriope2',
        '"vinca groundcover"' => 'google-vinca-groundcover',
        '"vinca major"' => 'google-vinca-major1',
        '"vinca minor bowles"' => 'google-vinca-minor-bowles',
        '"vinca minor"' => 'google-vinca-minor1',
        '"vinca periwinkle"' => 'google-vinca-periwinkle',
        '"vinca plants"' => 'google-vinca-plants',
        '"vinca vines"' => 'google-vinca-vines',
        '"vinca"' => 'google-vinca2',
        '[english ivy]' => 'google-english-ivy',
        '[ice plant]' => 'google-ice-plant2',
        '[liriope plant]' => 'google-liriope-plant',
        '[liriope variegata]' => 'google-liriope variegata',
        '[pacasandra]' => 'google-pacasandra',
        '[variegated liriope]' => 'google-variegated-liriope',
        '[varigated liriope]' => 'google-varigated-liriope',
        '[vinca minor bowles]' => 'google-vinca-minor-bowles3',
        '[vinca vines]' => 'google-vinca-vines3',
        '[vinca]' => 'google-vinca3',
        'buy ground cover plants' => 'google-buy-ground-cover-plants3',
        'english ivy' => 'google-english-ivy3',
        'flowering vinca' => 'google-flowering-vinca3',
        'ground covers' => 'google-ground-covers3',
        'groundcover' => 'google-groundcover3',
        'groundcovers' => 'google-groundcovers3',
        'ice plant' => 'google-ice-plant3',
        'lariope' => 'google-lariope1',
        'liriope' => 'google-liriope3',
        'liriope plant' => 'google-liriope-plant1',
        'liriope variegata' => 'google-liriope variegata2',
        'pacasandra' => 'google-pacasandra2',
        'periwinkle flowers' => 'google-periwinkle-flowers3',
        'periwinkle vinca' => 'google-periwinkle-vinca3',
        'trailing vinca' => 'google-trailing-vinca3',
        'variegated liriope' => 'google-variegated-liriope2',
        'varigated liriope' => 'google-varigated-liriope3',
        'vinca groundcover' => 'google-vinca-groundcover3',
        'vinca minor bowles' => 'google-vinca-minor-bowles2',
        'vinca periwinkle' => 'google-vinca-periwinkle3',
        'vinca plants' => 'google-vinca-plants3',
        'vinca vines' => 'google-vinca-vines2'
    );


    /**
     * Convert Google Adwords keyword (as got from their API) to
     * our internal representation (as saved in the database).
     * @input string Google Adwords keyword
     * @return string Converted string to our internal representation
     */
    static public function convertKeyword($x) {
        if (!strlen($x)) { return ''; }
        $x = strtolower($x);
        // see the list of exceptions
        if (array_key_exists($x, self::$exceptions)) {
            $x = self::$exceptions[$x];
            return $x;
        }
        // Do standard transformations
        $suffix = '';
        if ($x[0] == '[') {
            $suffix = '2';
            $x = substr($x, 1, strlen($x) - 2);
        } else if ($x[0] == '"') {
            $suffix = '3';
            $x = substr($x, 1, strlen($x) - 2);
        }
        $x = 'google-' . str_replace(' ', '-', $x) . $suffix;

        return $x;
    }


    /**
     * Tested 2011-11-30 for v201109
     * Get keywords for adgroup from Google.
     * @param int $adgroupID
     * @param array &$keywords [out]
     *      When no stats are required, it returns a simple array of keywords.
     *      When stats are needed, it returns array of hashes, each is
     *      key => value map, having various stats and other information.
     *      See the function for more information about the fields returned.
     * @param string &$error [out]
     * @param boolean $converted If to convert keywords to our internal representation
     * @param string $statsStart Date formatted via self::formatDate()
     *      or false if no stats are needed
     * @param string $statsEnd Date formatted via self::formatDate()
     *      or false if no stats are needed
     * @return boolean
     */
    static public function keywordsForAdgroup($adgroupID, &$keywords,
                                              &$error, $converted = false, $statsStart = false, $statsEnd = false) {
        $keywords = array();
        $doStats = ($statsStart !== false) && ($statsEnd !== false);

        // Log SOAP XML request and response.
        try {
            initCredentials($session);
            $adWordsServices = new AdWordsServices();
            //$user->LogDefaults();
        } catch (Exception $e) {
            $error = 'Can not create AdWordsUser';
            return false;
        }
        $adGroupCriterionService = $adWordsServices->get($session,AdGroupCriterionService::class);
        $selector = new Selector();
        $selector->setFields(array(//'AdGroupId', 'CriterionUse', 'Status',
            'CriteriaType', 'KeywordMatchType', 'KeywordText'));
        $pre1 = new Predicate('AdGroupId', 'EQUALS', array($adgroupID));
        $pre2 = new Predicate('CriterionUse', 'EQUALS', array('BIDDABLE'));
        $pre3 = new Predicate('Status', 'IN', array('ENABLED', 'PAUSED'));
        $selector->setPredicates([$pre1,$pre2,$pre3]);
        if($doStats){
            $selector = new Selector();
            $selector->setFields(["CampaignId", "CampaignName", "AdGroupId", "AdGroupName", "AveragePosition", "Criteria", "KeywordMatchType", "Date", "Impressions", "Clicks", "Cost", "Device", "AccountDescriptiveName", "FinalUrls", "QualityScore", "Conversions"]);
            // Create report definition.
            $reportDefinition = new ReportDefinition();

        }
        if ($doStats) {
            $dateRange = new DateRange();
            $dateRange->setMin($statsStart);
            $dateRange->setMax($statsEnd);
            $selector->setDateRange($dateRange);
        }
//        $selector->setPaging(new Paging(0,10));

        try {
//           // $result = $adGroupCriterionService->get($selector);
        $reportDefinition->setSelector($selector);
        $reportDefinition->setReportName(
            'Keyword performance report #' . uniqid());
        $reportDefinition->setDateRangeType(
            ReportDefinitionDateRangeType::CUSTOM_DATE);
        $reportDefinition->setReportType(
            ReportDefinitionReportType::KEYWORDS_PERFORMANCE_REPORT);
        $reportDefinition->setDownloadFormat(DownloadFormat::XML);
        // Download report.
        $reportDownloader = new ReportDownloader($session);
        // Optional: If you need to adjust report settings just for this one
        // request, you can create and supply the settings override here. Otherwise,
        // default values from the configuration file (adsapi_php.ini) are used.
        $reportSettingsOverride = (new ReportSettingsBuilder())
            ->includeZeroImpressions(false)
            ->build();
        $result = $reportDownloader->downloadReport(
            $reportDefinition, $reportSettingsOverride);

        $result->saveToFile(__DIR__.'test.xml');
        } catch (SoapFault $fault) {
            $error = "SOAP error: " . $fault->faultstring;
            return false;
        }

//        if (!count($result->getEntries())) {
//            // No keywords
//            return true;
//        }
//
//        for ($i = 0; $i < count($result->getEntries()); $i++) {
//            $item = $result->getEntries()[$i];
//            if (!$item) { continue; }
//
//            if ($item->getCriterion()->getType() != 'KEYWORD') { continue; }
//
//            // Change keyword to convenient Google representation:
//            $wrap1 = '';
//            $wrap2 = '';
//            $matchType = $item->getCriterion()->getMatchType();
//            if ($matchType == 'PHRASE') {
//                $wrap1 = '"';
//                $wrap2 = '"';
//            } else if ($matchType == 'EXACT') {
//                $wrap1 = '[';
//                $wrap2 = ']';
//            }
//            $k = $wrap1 . $item->getCriterion()->getText() . $wrap2;
//            //echo "<br/>Got: $k";
//            if ($converted) {
//                $k = self::convertKeyword($k);
//                //echo " => $k";
//            }
//            if ($doStats) {
//                // put data into an object
//                $x = array();
//                $x['keyword'] = $k;
//                $x['adgroupID'] = $adgroupID;
//
//                $x['averagePosition'] = $item->getstatus()->getAveragePosition();
//                $x['impressions'] = $item->getstatus()->getImpressions();
//                $x['clicks'] = $item->getstatus()->getClicks();
//                $x['ctr'] = $item->getStatus()->getCtr();
//                $x['averageCpc'] = $item->getStatus()->getAverageCpc()->getMicroAmount()/ 1000000;
//                $x['cost'] = $item->getStatus()->getCost()->getMicroAmount() / 1000000;
//
//                if (isset($keywords[$k])) {
//                    // merge them
//                    $x = self::merge2Keywords($x, $keywords[$k]);
//                }
//
//                // Assign here
//                $keywords[$k] = $x;
//            } else {
//                $keywords[] = $k;
//            }
//        }

        $result = null;
        return true;
    }

    /**
     * @return array Hash for a merged object
     */
    public static function merge2Keywords($x, $y) {
        $z = array();
        $z['keyword'] = $x['keyword'];
        $z['adgroupID'] = $x['adgroupID'];
        $z['impressions'] = $x['impressions'] + $y['impressions'];
        $z['clicks'] = $x['clicks'] + $y['clicks'];
        $z['cost'] = $x['cost'] + $y['cost'];
        $z['averagePosition'] = $z['impressions'] ? (
            ($x['averagePosition'] * $x['impressions'] +
                $y['averagePosition'] * $y['impressions']) /
            $z['impressions']
        ) : 0.0;
        $z['ctr'] = $z['impressions'] ? ($z['clicks'] / $z['impressions']) : 0.0;
        $z['averageCpc'] = $z['clicks'] ? ($z['cost'] / $z['clicks']) : 0.0;

        return $z;
    }
}


/**
 * Tested 2011-11-30 for v201109
 * Get all adgroups for a campaign
 * @param int $campaignID
 * @param array &$list [out] array of AdGroup objects
 * @param string &$error [out] Filled in case of failure
 * @return boolean, in case of failure fills in $error
 */
function getAdgroups($campaignID, &$list, &$error) {

    $list = array();

    global $GOOGLE_VERSION;

    // Log SOAP XML request and response.
    try {
        initCredentials($session);
        $adWordsServices = new AdWordsServices();
    } catch (Exception $e) {
        $error = 'Can not create AdWordsUser';
        return false;
    }
    $adGroupService = $adWordsServices->get($session, AdGroupService::class);

    $selector = new Selector();
    $selector->setFields(array('Id', 'Status', 'Name')) ;
    $selector->setPredicates(array(
        new Predicate('CampaignId', 'IN', array($campaignID))));
    $selector->setOrdering(array(new OrderBy('Id', 'ASCENDING')));

    try {
        $result = $adGroupService->get($selector);
    } catch (SoapFault $fault) {
        $error = "SOAP error: " . $fault->faultstring;
        return false;
    }

    if (!count($result->getEntries())) {
        // No matching adgroups
        return true;
    }
    $list = $result->getEntries();

    return true;
}


/**
 * Get info of all campaigns
 * @param array &$list [out] array of Campaign objects
 * @param string &$error [out] Filled in case of failure
 * @return boolean, in case of failure fills in $error
 */
function getCampaigns(&$list, &$error) {

    $list = array();
    // Log SOAP XML request and response.
    try {
        initCredentials($session);
        $adWordsServices = new AdWordsServices();

    } catch (Exception $e) {
        $error = 'Can not create AdWordsUser';
        return false;
    }
    $campaignService = $adWordsServices->get($session, CampaignService::class);

    $selector = new selector();
    $selector->setFields(array('Id', 'Status', 'Name'));
    $selector->setPredicates(array(
        new Predicate('Status', 'IN', array('ENABLED', 'PAUSED'))));
    $selector->setPaging(new Paging(0,10000)) ;

    try {
        $result = $campaignService->get($selector);
    } catch (SoapFault $fault) {
        $error = "SOAP error: " . $fault->faultstring;
        return false;
    }

    if (!count($result->getEntries())) {
        // No matching adgroups
        return true;
    }

    $list = $result->getEntries();

    return true;
}


// Get cost for date range for all of our campaigns
// @return float cost or FALSE in case of error
function getCampaignCostAllForDateRange($startDay, $endDay, &$error) {
    $grossCost = 0;
    $cost=0;
    foreach (PPCSettings::getAllCampaignIDs() as $campaignID) {
        if (getCampaignCost($campaignID, $startDay, $endDay, $cost, $error)) {
            //$cost = sprintf("%.2f", $cost);
            $grossCost += $cost;
        } else {
            return false;
        }
    }
    return sprintf("%.2f", $grossCost) + 0;
}
function initCredentials(&$session){
    $oAuth2Credential = (new OAuth2TokenBuilder())
        ->fromFile(ADWORDS_CONFIGURATION_FILE)
        ->build();
    // Construct an API session configured from a properties file and the OAuth2
    // credentials above.
    $soapSettings = (new SoapSettingsBuilder())
        ->disableSslVerify()
        ->build();
    $session = (new AdWordsSessionBuilder())
        ->fromFile(ADWORDS_CONFIGURATION_FILE)
        ->withSoapSettings($soapSettings)
        ->withOAuth2Credential($oAuth2Credential)
        ->build();
    return true;
}