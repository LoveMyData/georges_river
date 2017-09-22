<?php
### Georges River Council scraper
require_once 'vendor/autoload.php';
require_once 'vendor/openaustralia/scraperwiki/scraperwiki.php';

use PGuardiario\PGBrowser;
use Sunra\PhpSimple\HtmlDomParser;

date_default_timezone_set('Australia/Sydney');

# Default to 'thisweek', use MORPH_PERIOD to change to 'thismonth' or 'lastmonth' for data recovery
switch(getenv('MORPH_PERIOD')) {
    case 'thismonth' :
        $period = 'thismonth';
        break;
    case 'lastmonth' :
        $period = 'lastmonth';
        break;
    default          :
        $period = 'thisweek';
        break;
}
print "Getting data for " .$period. ", changable via MORPH_PERIOD environment\n";

$url_base = "https://daenquiry.georgesriver.nsw.gov.au/masterviewui/Modules/applicationmaster/";
$da_page = $url_base . "default.aspx?page=found&1=" .$period. "&4a=DA%27,%27S96Mods%27,%27Mods%27,%27Reviews&6=F";
$comment_base = "mailto:mail@georgesriver.nsw.gov.au?subject=Development Application Enquiry: ";

# Agreed Terms
$browser = new PGBrowser();
$page = $browser->get($url_base . "default.aspx");
$form = $page->form();
$form->set('ctl00$cphContent$ctl00$Button1', 'Agreed');
$page = $form->submit();

# Assume Terms page passed
$page = $browser->get($da_page);
$dom = HtmlDomParser::str_get_html($page->html);

# By default, assume it is single page
$dataset  = $dom->find("tr[class=rgRow], tr[class=rgAltRow]");
$NumPages = count($dom->find('div[class=rgWrap rgNumPart] a'));
if ($NumPages === 0) { $NumPages = 1; }

for ($i = 1; $i <= $NumPages; $i++) {
    echo "Scraping page $i of $NumPages\r\n";

    # If more than a single page, fetch the page
    if ($NumPages > 1) {
        $form = $page->form();
        $page = $form->doPostBack($dom->find('div[class=rgWrap rgNumPart] a', $i-1)->href);
        $dom  = HtmlDomParser::str_get_html($page->html);
        $dataset = $dom->find("tr[class=rgRow], tr[class=rgAltRow]");
    }

    # The usual, look for the data set and if needed, save it
    foreach ($dataset as $record) {
        # Slow way to transform the date but it works
        $date_received = explode(' ', (trim($record->children(2)->plaintext)), 2);
        $date_received = explode('/', $date_received[0]);
        $date_received = "$date_received[2]-$date_received[1]-$date_received[0]";

        # Prep a bit more, ready to add these to the array
        $tempstr = explode('<br/>', $record->children(3)->innertext);

        # Put all information in an array
        $application = [
            'council_reference' => trim($record->children(1)->plaintext),
            'address'           => trim($record->children(3)->children(0)->plaintext) . ", NSW",
            'description'       => preg_replace('/\s+/', ' ', $tempstr[1]),
            'info_url'          => $url_base . trim($record->find('a',0)->href),
            'comment_url'       => $comment_base . trim($record->children(1)->plaintext),
            'date_scraped'      => date('Y-m-d'),
            'date_received'     => date('Y-m-d', strtotime($date_received))
        ];

        # Check if record exist, if not, INSERT, else do nothing
        $existingRecords = scraperwiki::select("* from data where `council_reference`='" . $application['council_reference'] . "'");
        if (count($existingRecords) == 0) {
            print ("Saving record " . $application['council_reference'] . "\n");
//             print_r ($application);
            scraperwiki::save(['council_reference'], $application);
        } else {
            print ("Skipping already saved record " . $application['council_reference'] . "\n");
        }
    }
}

