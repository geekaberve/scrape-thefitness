<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;

$clubs = array("hala" => 1, "zavrtnica" => 1, "branimir" => 1, "greengold" => 1, "hob" => 1, "kaptol" => 1);

$baseUrl = "https://hob.thefitness.hr";

if (isset($_GET['club']) && isset($clubs[$_GET['club']])) {
    $baseUrl = "https://".$_GET['club'].".thefitness.hr";
} else {
    header('Content-Type: text/html; charset=UTF-8');
    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Scraped Page</title>
    </head>
    <body>
    ";

    foreach ($clubs as $club => $value) {
        echo "<a href=\"?club=$club\">$club</a><br/>";
    }

    echo "</body>
    </html>";
    die();
}

// Base URL and endpoint
$endpoint = "/calendar";

// Function to resolve relative URLs
function resolve_relative_url($baseUrl, $relativeUrl) {
    return strpos($relativeUrl, 'http') === 0 ? $relativeUrl : rtrim($baseUrl, '/') . '/' . ltrim($relativeUrl, '/');
}

// Create a Guzzle HTTP client
$client = new Client([
    'base_uri' => $baseUrl,
    'headers' => [
        'User-Agent' => 'PHP-Scraper',
    ],
]);

// Fetch the webpage content
$response = $client->request('GET', $endpoint);

if ($response->getStatusCode() !== 200) {
    die("Failed to fetch content: " . $response->getReasonPhrase());
}

$htmlContent = (string) $response->getBody();

// Load the HTML content into DOMDocument
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$htmlContent = mb_convert_encoding($htmlContent, 'HTML-ENTITIES', 'UTF-8');
$htmlContent = '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">' . $htmlContent;
$dom->loadHTML($htmlContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();

// Function to replace relative URLs in $.get calls
function replace_relative_urls_in_get($scriptContent, $baseUrl) {
    return preg_replace_callback(
        "/\\$\\.get\\((['\"])(\\/[^'\"\\)]+)\\1/", // Match $.get with single/double quotes and a relative URL
        function ($matches) use ($baseUrl) {
            $quote = $matches[1];  // Preserve the quote type used (single or double)
            $relativeUrl = $matches[2];
            $absoluteUrl = resolve_relative_url($baseUrl, $relativeUrl);
            return "$.get({$quote}{$absoluteUrl}{$quote}";
        },
        $scriptContent
    );
}

// Resolve relative URLs for imports in the <head>
$headContent = "";
$links = $dom->getElementsByTagName("link");
foreach ($links as $link) {
    if ($link->getAttribute("rel") === "stylesheet") {
        $href = resolve_relative_url($baseUrl, $link->getAttribute("href"));
        $headContent .= "<link rel='stylesheet' href='{$href}'>\n";
    }
}

$scripts = $dom->getElementsByTagName("script");
$scriptsContent = "";
foreach ($scripts as $script) {
    if ($script->hasAttribute('src')) {
        $src = resolve_relative_url($baseUrl, $script->getAttribute('src'));
        $headContent .= "<script src='{$src}'></script>\n";
    } else {
        $scriptContent = $script->nodeValue;
        $modifiedScriptContent = replace_relative_urls_in_get($scriptContent, $baseUrl);
        $script->nodeValue = $modifiedScriptContent;
        $scriptsContent .= $dom->saveHTML($script);
    }
}

$schedulerContent = "";
$xpath = new DOMXPath($dom);
$schedulerNode = $xpath->query("//*[@id='scheduler']")->item(0);

if ($schedulerNode) {
    // Extract the HTML of #scheduler
    $schedulerContent = $dom->saveHTML($schedulerNode);

    // Replace relative links in scripts within #scheduler
    $schedulerContent = preg_replace_callback(
        '/\$.get\((["\'])(\/[^"\']+)\1/', // Matches $.get with a relative URL
        function ($matches) use ($baseUrl) {
            $relativePath = $matches[2];
            $absoluteURL = $baseUrl . $relativePath;
            return str_replace($matches[2], $absoluteURL, $matches[0]);
        },
        $schedulerContent
    );
}

$schedulerContent = mb_convert_encoding($schedulerContent, 'HTML-ENTITIES', 'UTF-8');

$scrollDom = new DOMDocument();
libxml_use_internal_errors(true);

// Load the HTML content into DOMDocument
$scrollDom->loadHTML($schedulerContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();

// Use XPath to query for all divs with class "event"
$xpath = new DOMXPath($scrollDom);
$eventDivs = $xpath->query("//div[contains(@class, 'event')]");

foreach ($eventDivs as $eventDiv) {
    // Check if the div contains a <p> element with the class "event_name" and value "Padel"
    $eventNameNode = $xpath->query(".//p[@class='event_name' and normalize-space(text())='Padel']", $eventDiv);
    if ($eventNameNode->length > 0) {
        // Remove the div if it matches
        $eventDiv->parentNode->removeChild($eventDiv);
    }
}

// Save the modified HTML
$schedulerContent = $scrollDom->saveHTML();
// Extract #OverlayEvent content
$overlayEvent = $dom->getElementById("OverlayEvent");
$overlayEventContent = $overlayEvent ? $dom->saveHTML($overlayEvent) : "";

// Add missing header .topbanner .logo
$headerContent = "
<header style=\"display:none\">
    <div class='topbanner'>
        <div class='logo'>
            <img src='" . resolve_relative_url($baseUrl, "/path-to-logo.png") . "' alt='Logo'>
        </div>
    </div>
</header>";

// Build the final HTML content
$htmlOutput = "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Scraped Page</title>
    <base href=\"$baseUrl\">
    {$headContent}
    <style>
        #calendar-register-for-error, #calendar_other_instructor, #calendar_other_class, .calendar-register-for-class, .scrollindicator {
            display:none !important;
        }
    </style>
</head>
<body>
    {$headerContent}
    {$schedulerContent}
    <div class=\"calendar_main\">
        {$overlayEventContent}
    </div>
</body>
</html>";

// Save to an HTML file
// file_put_contents('scraped_page.html', $htmlOutput);

header('Content-Type: text/html; charset=UTF-8');
echo $htmlOutput;

// echo "Scraped page saved to scraped_page.html\n";

?>
