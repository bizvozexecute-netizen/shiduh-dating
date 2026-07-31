<?php

function show_time() {
	static $start;
	static $c_time;
	if (!$start) {
		$start = microtime(true);
		$c_time = $start;
		return;
	}
	$now = microtime(true);
	$last_diff = $now - $c_time;
	$g_diff = $now - $start;
	$c_time = $now;
	return array(round($g_diff,3), round($last_diff,3));
}

function fix_time($label = false, $print = false) {
	if (! ($timing = show_time()) ) {
		return;
	}

	$bt = debug_backtrace();
	static $entries = array();
	$entry = array(
		'file'   => $bt[0]['file'],
		'line'   => $bt[0]['line'],
		'global' => $timing[0],
		'diff'   => $timing[1],
		'label'  => $label
	);
	$entries [] = $entry;
	if ($print) {
		$droot = preg_replace("~[/\\\]~", DIRECTORY_SEPARATOR, $_SERVER['DOCUMENT_ROOT']);
		?>
		<style type="text/css">
		table.timing {
			border-collapse:collapse;
		}
		table.timing td {
			border:1px solid #333;
			font-size:11px;
			font-family:arial;
			padding:2px 4px;
		}
		</style>
		<table class="timing">
			<?
			foreach ($entries as $e) {
				$file = str_replace($droot, '', $e['file']);
				?><tr>
					<td><?="<pre>".htmlspecialchars(print_r($e['label'],1))."</pre>"?></td>
					<td><?=$e['global']?></td>
					<td>+<?=$e['diff']?></td>
					<td><?=$file?></td>
					<td><?=$e['line']?></td>
				</tr>
				<?
			}
			?>
		</table>
		<?
	}
}

$NETCAT_FOLDER = join(strstr(__FILE__, "/") ? "/" : "\\", array_slice(preg_split("/[\/\\\]+/", __FILE__), 0, -3)).( strstr(__FILE__, "/") ? "/" : "\\" );
include_once ($NETCAT_FOLDER."vars.inc.php");
require_once ($ADMIN_FOLDER."function.inc.php");
require_once ($ADMIN_FOLDER."admin.inc.php");
require_once ($ADMIN_FOLDER."catalogue/function.inc.php");

$inside_admin = 1;

?>
<html>
    <head>
        <meta http-equiv='Content-Type' content='text/html; charset=<?= $nc_core->NC_CHARSET ?>'>
        <title><?= (isset($title) ? $title : SECTION_INDEX_TITLE) ?></title>
        <link type='text/css' rel='Stylesheet' href='<?= nc_add_revision_to_url($ADMIN_TEMPLATE . 'css/admin.css') ?>'>
        <?= nc_js(); ?>
        <?php
        // MODULE URL DISPATCHERS
        $modules = $db->get_results("SELECT Keyword, Module_Name FROM Module ORDER BY Keyword", ARRAY_A);
        if ( !empty($modules) ) {
			foreach ($modules as $module) {
				if (file_exists(nc_module_folder($module['Keyword']). MAIN_LANG . '.lang.php')) {
					require_once nc_module_folder($module['Keyword']) . MAIN_LANG . '.lang.php';
				} else {
					require_once nc_module_folder($module['Keyword']) . 'en.lang.php';
				}
			}
		}
        include $ADMIN_FOLDER . 'modules/module_list.inc.php';
        ?>

    </head>


    <body class='admin_form' style='margin: 0px;'>
        <?php
        $favorites = GetFavorites();

        $nc_settings = $nc_core->get_settings();

		if ($nc_settings['InstallationID']):
			echo "<table border='0' width=100% id='main_page_table'><tr><td>";
			if ($nc_settings['ProductNumber'] && $nc_settings['Code']) {
				$text = sprintf(TOOLS_ACTIVATION_REMIND_UNCOMPLETED, $ADMIN_PATH.'/patch/activation.php');
				echo nc_print_status($text, 'info', null, true);
			}
			else {
				$text = str_replace("%DAY", intval( ( strtotime($nc_settings['InstallationDateOut']) - time() ) / 86400 + 1), TOOLS_ACTIVATION_DAY);
				echo nc_print_status($text, 'error', null, true);
			}
			echo "</td></tr></table>";
		endif;

        echo "<table border='0' width=100% id='main_page_table'>\n";
        echo "<tr>\n";
        echo "<td width=48%>\n";

        //Welcome
        echo "<div class='main_page_text'>\n";
        echo "\t<div class='block_title'>\n";
        //echo "\t\t<img src='".$ADMIN_TEMPLATE."img/i_netcat_big.gif' alt='Netcat'>\n";
        echo "\t\t<span>".SECTION_INDEX_WELCOME."</span>\n";
        echo "\t</div>\n";
        echo "\t<div class='block_text'>\n";
        printf(SECTION_INDEX_WELCOME_MESSAGE, $perm->getLogin(), $PROJECT_NAME, $perm->GetMaxPerm());
        echo "\t</div>\n";
        echo "</div>\n";
        echo "<br style='clear: both'>";

        //Favorites
        if ($perm->isAccessSiteMap() || $perm->isGuest()) {
            echo "<div class='main_page_block'>\n";
            echo "\t<div class='block_title'>\n";
            //echo "\t\t<img src='".$ADMIN_TEMPLATE."img/i_favorites_big.gif' alt='".FAVORITE_HEADERTEXT."'>\n";
            echo "\t\t<span>".FAVORITE_HEADERTEXT."</span>\n";
            echo "\t</div>\n";
            if ($favorites) {
                $i = 0;
                foreach ($favorites as $fkey=>$favorite) {

                    if ($catalogue != $favorite['Catalogue_ID']) {
                        echo "\t<div class='block_title_line'>";
                        echo "\t\t<i class='nc-icon nc--site'></i>";
                        $domainError = checkDomain($favorite['Domain'], $favorite['Catalogue_ID']);
                        echo "\t\t<span><a href='subdivision/full.php?CatalogueID=".$favorite['Catalogue_ID']."'".(!$favorite['CatalogueChecked'] ? " class='gray'" : "").">".$favorite['Catalogue_Name']."</a>".$domainError."</span>\n";
                        echo "\t</div>\n";
                        $catalogue = $favorite['Catalogue_ID'];
                        $count++;
                    }

                    $subclass_id = $db->get_var("SELECT Sub_Class_ID
                                       FROM Sub_Class
                                      WHERE Subdivision_ID = '".$favorite['Subdivision_ID']."'
                                   ORDER BY Priority
                                      LIMIT 1");

                    echo "\t<div class='".(($i % 2) ? "block_line" : "block_line_gray")."'>\n";
                    echo "\t\t<i class='nc-icon nc--folder".(!$favorite['SubChecked'] ? " nc--disabled" : "")."'></i>\n";
                    echo "\t\t<span><a href='".($subclass_id ? "../?inside_admin=1&cc=$subclass_id" : "subdivision/SubClass.php?phase=1&SubdivisionID=".$favorite['Subdivision_ID'])."' title='".$favorite['Subdivision_Name']."'".(!$favorite['SubChecked'] ? " class='gray'" : "").">".$favorite['Subdivision_Name']."</a></span>\n";
                    echo "\t\t<div class='block_line_link'><img border='0' src=".$ADMIN_TEMPLATE."img/px.gif width='16' height='16' style='margin:0px 2px 0px 2px;'>".($subclass_id ? "<a href='http://".$EDIT_DOMAIN.$SUB_FOLDER.$HTTP_ROOT_PATH."?inside_admin=1&cc=".$subclass_id.(strlen(session_id()) > 0 ? "&".session_name()."=".session_id()."" : "")."'><i class='nc-icon nc--edit nc--hovered' title='".CONTROL_CONTENT_CATALOUGE_FUNCS_SHOWMENU_A_EDIT."'></i></a>" : "<img border='0' src=".$ADMIN_TEMPLATE."img/px.gif width='19' height='19' style='margin:0px 2px 0px 2px;'>")."<a href='".nc_subdivision_preview_link($favorite)."' target='_blank'><i class='nc-icon nc--arrow-right nc--hovered' title='".CONTROL_CONTENT_SUBDIVISION_FUNCS_TOVIEW."'></i></a></div>\n";
                    echo "\t</div>\n";
                    if ($catalogue != $favorites[$fkey+1]['Catalogue_ID']) {
                        echo "\t<div class='block_line block_map'>";
                        echo "\t\t<span><a href='subdivision/full.php?CatalogueID=".$favorite['Catalogue_ID']."'".(!$favorite['CatalogueChecked'] ? " class='gray'" : "").">".NETCAT_TREE_SITEMAP."</a></span>\n";
                        echo "\t</div>\n";
                    }

                    $i++;

                }

            } else {
                echo "\t<div class='block_line_gray'>\n";
                echo "\t\t<span>".CONTROL_CONTENT_SUBDIVISION_FUNCS_NOONEFAVORITES."</span>\n";

                echo "\t</div>\n";
            }

            echo "</div>\n";
            echo "<br style='clear: both;'>";

            echo "<div class='main_page_text main_right'>\n";
            echo "\t<div class='block_text'>\n";
            echo "\t\t<div><a class='nc-btn nc--bordered nc--blue nc--right' href='subdivision/favorites.php?phase=1'>".SECTION_INDEX_FAVORITE_SETTINGS."</a></div>\n";
            echo "\t</div>\n";
            echo "</div>\n";
        }

        echo "</td>";
        echo "<td width=20>&nbsp;</td>";
        echo "<td width=48%>";
        if ($perm->isSupervisor() || $perm->isGuest()) {
            //System info
            echo "<div class='main_page_text'>\n";
            echo "\t<div class='block_title'>\n";
            //echo "\t\t<img src='".$ADMIN_TEMPLATE."img/i_info_big.gif' alt='".SECTION_INDEX_ADMIN_PATCHES_INFO."'>\n";
            echo "\t\t<span>".SECTION_INDEX_ADMIN_PATCHES_INFO."</span>\n";
            echo "\t</div>\n";

            echo "\t<div class='block_text'>\n";
            echo "\t\t".SECTION_INDEX_ADMIN_PATCHES_INFO_VERSION.": <b>".$VERSION_ID." ".( $nc_core->NC_UNICODE ? "UTF-8" : "")." </b><br>";
            if ($LAST_LOCAL_PATCH)
                    echo "\t\t".SECTION_INDEX_ADMIN_PATCHES_INFO_LAST_PATCH."#: <b>".$LAST_LOCAL_PATCH."</b><br>";
            echo "\t\t".SECTION_INDEX_ADMIN_PATCHES_INFO_LAST_PATCH_DATE.": <b>".date("d.m.Y H:i", $PATCH_CHECK_DATE)."</b><br>";
            //echo "\t\t<img src='".$ADMIN_TEMPLATE."img/i_tool_patch.gif' alt='".SECTION_INDEX_ADMIN_PATCHES_INFO_CHECK_PATCH."'>";
            echo "<span><a href='patch/?phase=3'>".SECTION_INDEX_ADMIN_PATCHES_INFO_CHECK_PATCH."</a></span>";
            echo "\t</div>\n";

            echo "</div>\n";
        }
        echo "<br style='clear: both'>";

        if ($perm->isSupervisor() || $perm->isGuest()) {
            //Modules
            echo "<div class='main_page_block'>\n";
            echo "\t<div class='block_title'>\n";
            //echo "\t<img src='".$ADMIN_TEMPLATE."img/i_modules_big.gif' alt='".NETCAT_MODULES."'>\n";
            echo "\t<span>".NETCAT_MODULES."</span>\n";
            echo "\t</div>\n";
            $have_modules = "";
            if ($modules = $db->get_results("SELECT Module_ID,Module_Name,Keyword FROM Module")) {
                $i = 0;
                foreach ((array) $modules as $module) {
                    $have_modules[$module->Keyword] = 1;

                    echo "\t<div class='".(($i % 2 == 0) ? "block_line_gray" : "block_line")."'>\n";
                    echo "\t\t<i class='nc-icon nc--mod-".$module->Keyword."' title='".constant($module->Module_Name)."'></i>\n";
                    echo "\t\t<span><a href='" . (file_exists(nc_module_folder($module->Keyword) . 'admin.php') ? nc_module_path($module->Keyword) . 'admin.php' : nc_module_path() . 'index.php?phase=2&module_name=' . $module->Keyword) . "' title='" . constant($module->Module_Name) . "'>" . constant($module->Module_Name) . "</a></span>\n";
                    echo "\t\t<div class='block_line_link'><a href='modules/index.php?phase=2&module_name=".$module->Keyword."'><i class='nc-icon nc--settings nc--hovered' title='".TOOLS_MODULES_MOD_PREFS."' title='".TOOLS_MODULES_MOD_PREFS."'></i></a></div>\n";
                    echo "\t</div>\n";

                    $i++;
                    unset($modImg);
                }
            }
            $pre_modules_must_have = "\t<div class='block_unordered'><span>".SECTION_INDEX_MODULES_MUSTHAVE."</span></div>";
            foreach ($real_modules as $key => $real_module) {
                if (!$have_modules[$key] && $key != 'eshop' && $key != 'forum') {
                    $modules_must_have .= "\t<div class='".(($i % 2 == 0) ? "block_line_gray" : "block_line")." unordered'>\n";
                    $modules_must_have .= "\t\t<i class='nc-icon nc--mod-".$key." nc--hovered' title='".$real_module."'></i>\n";
                    $modules_must_have .= "\t\t<span>".$real_module."</span>\n";
                    $modules_must_have .= "\t\t<div class='block_line_link'><a href='http://www.netcat.ru/products/modules/$key.html' target='_blank'>".SECTION_INDEX_MODULES_DESCRIPTION."</a></div>\n";
                    $modules_must_have .= "\t</div>\n";

                    $i++;
                }
            }
            if ($modules_must_have) {
                echo $pre_modules_must_have.$modules_must_have;
                echo "\t\t<div class='block_line_link' style='float: left; padding-left: 20px;'><br /><a href='http://www.netcat.ru/products/upgrade/' target='_blank'>".SECTION_INDEX_MODULES_TRANSITION."</a></div>\n";
            }
            echo "</div>\n";
        }

        echo "</td>";
        echo "</tr>";
        echo "</table>";

        $treeMode = 'users';
        if ($perm->isAccessDevelopment()) {
            $treeMode = 'developer';
        }
        if ($perm->isAccessSiteMap() || $perm->isGuest()) {
            $treeMode = 'sitemap';
        }

        $UI_CONFIG = new ui_config(array(
                        'headerText'   => SECTION_INDEX_TITLE,
                        'tabs'         => array(array('id' => 'welcame', 'caption' => SECTION_INDEX_TITLE)),
                        'activeTab'    => 'welcame',
                        'treeMode'     => $treeMode,
                        'locationHash' => "index",
                ));
        global $UI_CONFIG;
        if (is_object($UI_CONFIG) && method_exists($UI_CONFIG, 'to_json')) {
            print $UI_CONFIG->to_json();
        }
        ?>
    </body>
</html>
