<meta http-equiv="Content-type" content="text/html;charset=UTF-8">
<?php
if( count($page_keywords) )
    echo '<meta name="keywords" content="' . implode(',', $page_keywords) . "\">\n";  //"
if( strlen($page_description) )
    echo "<meta name=\"description\" content=\"$page_description\">\n";
?>
<meta name="author" content="Patrick Meenan">
<meta http-equiv="X-UA-Compatible" content="IE=Edge"> 

<link rel="shortcut icon" href="/images/favicon.ico" type="image/vnd.microsoft.icon">
<link rel="icon" href="/images/favicon.png" type="image/png">

<?php 
    if(extension_loaded('newrelic'))
        echo @newrelic_get_browser_timing_header();
    if (is_file('./settings/customhead.inc.php')) {
        include('./settings/customhead.inc.php');
    }
    if (is_file('./js/user-timing-min.js')) {
      echo "<script type=\"text/javascript\">\n";
      include('./js/user-timing-min.js');
      if (is_file('./js/user-timing-rum-min.js'))
        include('./js/user-timing-rum-min.js');
      echo "\n</script>\n";
    }        
    echo "<link rel=\"stylesheet\" href=\"{$GLOBALS['cdnPath']}/pagestyle.css?v=" . VER_CSS . "\" type=\"text/css\">\n";
    echo "<style type=\"text/css\">\n";
    
    // switch to the private install logo if we're not running on webpagetest
    $privateInstall = true;
    if( stristr($_SERVER['HTTP_HOST'] , '.webpagetest.org') !== false )
        $privateInstall = false;

    $blkLogo = false;
    if (isset($_REQUEST['color'])) {
        $color = html2rgb($_REQUEST['color']);
        if ($color) {
            echo "body {background-color: #{$_REQUEST['color']};}";
            $max = max($color[0], $color[1], $color[2]);
            if( $max >= 192 )
                $blkLogo = true;
        }
    }
            
    if( $privateInstall )
    {
        if( $blkLogo )
            echo ".logo a {background-image: url(/images/logo_private_blk.png);}";
        else
            echo ".logo a {background-image: url(/images/logo_private.png);}";
    }
    elseif( $blkLogo )
    {
        echo ".logo a {background-image: url(/images/logo_blk.png);}";
    }

    // include a custom style if one exists
    if( is_file('./settings/custom.css') )
        include('./settings/custom.css');

    echo "\n</style>\n";
        
    // include the analytics code if it is appropriate
    if (!isset($noanalytics))
        include('analytics.inc');
        
        
function html2rgb($color)
{
    if ($color[0] == '#')
        $color = substr($color, 1);

    if (strlen($color) == 6)
        list($r, $g, $b) = array($color[0].$color[1],
                                 $color[2].$color[3],
                                 $color[4].$color[5]);
    elseif (strlen($color) == 3)
        list($r, $g, $b) = array($color[0].$color[0], $color[1].$color[1], $color[2].$color[2]);
    else
        return false;

    $r = hexdec($r); $g = hexdec($g); $b = hexdec($b);

    return array($r, $g, $b);
}
?>
