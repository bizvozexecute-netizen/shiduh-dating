<?
    $date = date("Y-m-d H:i:s", time() + 30 * 24 * 60 * 60);
    $key = "nc63968534304df688ffedc8ca2e3f59f4a26c255b010e5bc647121dca79e55017726";
    
    $cript_key = sha1("Net_^^_Cat" . $date . $key . substr($date, 7, 3));

    print "UPDATE `Settings` SET `Value` = '" . $cript_key . "' WHERE `Key` =  'InstallationID';<br>";
    print "UPDATE `Settings` SET `Value` = '" . $date . "' WHERE `Key` =  'InstallationDateOut';";
?>