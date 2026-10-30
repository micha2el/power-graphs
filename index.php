<?php

include("config.php");

########################################################################################
## config variables
########################################################################################
$debug = false;
$scale = 1000;
$battery_min = 10;
$links = array("index_quick.php","index_tables.php");
$links_names = array("Quickinfo","Tables");
$links_icons = array("pics/icon_quickinfo.svg","pics/icon_tables.svg");
$show_dect_toggle_button = true;

########################################################################################
## no more config below
########################################################################################
$check_dect_overwrite = file_exists("/home/tmp_www/dect_overwrite.tmp");
$file = simplexml_load_file($datafile);
$output = "";
$charts = "";
$show_inverter = true;
$show_charts = false;
if (isset($_GET['charts']) && strcmp($_GET['charts'],"yes")==0){
	$show_charts = true;
	$show_inverter = false;
}

$output.="Ausgelesen am: ".date("d.m.Y H:i:s.", filemtime($datafile))."<br>";
for ($i=0;$i<sizeof($links);$i++){
	if (isset($links_icons)){
		$output.="<a href='".$links[$i]."'><img src='".$links_icons[$i]."' width='64px'></a>\n";
	}else{
		$output.="<a href='".$links[$i]."'>".$links_names[$i]."</a><br>\n";
	}
}
if ($use_webcam){
	$output.="<a href='https://".$_SERVER["HTTP_HOST"]."/reo_live.php'><img src='pics/icon_cams.svg' width='64px'></a>";
	#$output.="<a href='https://".$_SERVER["HTTP_HOST"]."/pics_webcam.php'>Webcam Access</a><br>";
}
$output.="<a href='https://".$_SERVER["HTTP_HOST"]."/index_links.php'><img src='pics/icon_links.svg' width='64px'></a>";
#$output.="<a href='https://".$_SERVER["HTTP_HOST"]."/index_links.php'>Links</a><br/>";
if ($show_charts){
	$output.="<a href='".$_SERVER["PHP_SELF"]."'><img src='pics/icon_back.svg' width='64px'></a><br><br>";
	#$output.="<a href='".$_SERVER["PHP_SELF"]."'>back</a><br><br>";
}else{
	$output.="<a href='".$_SERVER["PHP_SELF"]."?charts=yes'><img src='pics/icon_charts.svg' width='64px'></a><br><br>";
	#$output.="<a href='".$_SERVER["PHP_SELF"]."?charts=yes'>Charts</a><br><br>";
}
if ($show_dect_toggle_button){
	$output.="<div style='align-items:center;display:flex;'><img src='pics/icon_boiler_off.svg' width='64px'>: <input type='checkbox' class='checkcolor' id='dect_overwrite' disabled ".($check_dect_overwrite?"checked":"").">&nbsp;<button onclick='callDectOverwrite(true);'>An</button>&nbsp;&nbsp;<button onclick='callDectOverwrite(false)'>Aus</button></div><br>";
}
$temps = "<b>Temperaturen:</b><br>";
$leistungen = "<b>Leistungen:</b><br>";
$verbrauch = "<b>Verbrauch:</b><br>";
$inverter ="<b>Wechselrichterdaten:</b><br>";
$blocks = "";

function createDCContainer($dc,$dc_small){
	$ret='<g _ngcontent-c4="" id="DcContainer">';
	$ret.='<polyline _ngcontent-c4="" class="svg-green-stroke" fill="none" id="line-dc" points="15,15 50,15 50,45" stroke-width="3"></polyline>';
	$ret.='<polyline _ngcontent-c4="" class="svg-green-stroke" fill="none" id="line-dc" points="15,15 15,45 50,45" stroke-width="3"></polyline>';
	$ret.='<circle _ngcontent-c4="" cx="15" cy="15" id="canvas-dc" r="14" class="svg-green"></circle>';
	$ret.='<svg _ngcontent-c4="" height="6" id="gid_3cee6747_0" width="16" x="18" y="42"><g _ngcontent-c4="" id="gid_3cee6747_3"><rect _ngcontent-c4="" class="svg-green" height="6" id="canvas-text-dc" rx="2" ry="2" width="16" x="0" y="0"></rect><text _ngcontent-c4="" fill="#ffffff" font-family="Roboto, Verdana" font-size="3.5" id="text-dc" text-anchor="middle" x="8" y="4"><tspan _ngcontent-c4="" id="gid_3cee6747_8">'.$dc.' W</tspan></text></g></svg>';
	$ret.='<svg _ngcontent-c4="" height="6" id="gid_3cee6746_0" width="16" x="42" y="25"><g _ngcontent-c4="" id="gid_3cee6746_3"><rect _ngcontent-c4="" class="svg-green" height="6" id="canvas-text-dc-small" rx="2" ry="2" width="16" x="0" y="0"></rect><text _ngcontent-c4="" fill="#ffffff" font-family="Roboto, Verdana" font-size="3.5" id="text-dc" text-anchor="middle" x="8" y="4"><tspan _ngcontent-c4="" id="gid_3cee6746_8">'.$dc_small.' W</tspan></text></g></svg>';
	$ret.='<svg _ngcontent-c4="" height="24" id="shape-dc" viewBox="0 0 85.04 85.04" width="24" x="2.5" y="2.5">';
	$ret.='<path _ngcontent-c4="" d="M37.226,79.223h-0.031H24.669h-0.031v-21.5c0-1.018,1.791-2.434,2.961-2.434h6.574c1.17,0,3.021,1.416,3.021,2.434v0.197c0.004,0.029,0.031,0.043,0.031,0.074V79.223z" fill="#ffffff"></path><path _ngcontent-c4="" d="M52.919,79.223H52.87H40.362h-0.047V45.138c0-1.018,1.789-2.434,2.959-2.434h6.572c1.172,0,3.023,1.416,3.023,2.434v0.184c0.008,0.068,0.049,0.087,0.049,0.172V79.223z" fill="#ffffff"></path><path _ngcontent-c4="" d="M68.612,79.223h-0.049H56.058h-0.051V49.697c0-1.018,1.791-2.433,2.963-2.433h6.572c0.676,0,1.543,0.485,2.188,1.079c0.188,0.141,0.338,0.307,0.473,0.49c0.023,0.031,0.045,0.055,0.066,0.086c0.195,0.301,0.344,0.619,0.344,0.957V79.223z" fill="#ffffff"></path><path _ngcontent-c4="" d="M52.851,27.771l-6.018-5.653l4.525-6.933l-8.244-0.466l-0.986-8.196l-6.611,4.947l-6.041-5.652l-1.889,8.037l-8.238-0.46l3.688,7.375l-6.609,4.947l7.6,3.274l-1.881,8.05l7.896-2.391l3.744,7.368l4.527-6.902l7.592,3.242l-0.957-8.202L52.851,27.771z M43.537,28.14c-1.289,2.402-3.236,3.997-5.85,4.783c-2.595,0.785-5.099,0.534-7.508-0.753c-2.408-1.287-4.008-3.235-4.795-5.847c-0.786-2.612-0.538-5.118,0.753-7.521c1.287-2.401,3.238-3.996,5.849-4.782c2.611-0.786,5.116-0.536,7.518,0.75c2.402,1.289,4,3.239,4.787,5.85C45.078,23.233,44.827,25.738,43.537,28.14z" fill="#ffffff"></path></svg>';
	$ret.='</g>';
	return $ret;
}
function createBatterieContainer($bat,$soc,$cycles,$bat_cap,$bat_min,$fchargecap){
	if ($bat<0){
		$color="green";
		$est_time=(int)ceil($bat_cap*(100-$soc)/100/abs($bat)*60);
	}else{
		$color="orange";
		if ($bat<>0)
			$est_time=(int)ceil($bat_cap*($soc)/100/abs($bat)*60);
		else
			$est_time="unknown";
	}
	if ($est_time>2880){
		$est_time="&infin;";
	}else if ($est_time>120){
		$whole = floor($est_time/60);
		$fraction = (int)ceil((($est_time/60)-$whole)*60);
		$est_time="&#126;".$whole."h ".$fraction."min";
	}else{
		$est_time="&#126;".$est_time."min";
	}
	$ret='<g _ngcontent-c4="" id="BatteryContainer">';
	$ret.='<g _ngcontent-c4="" id="gid_3cee6746_10"><polyline _ngcontent-c4="" class="svg-'.$color.'-stroke" fill="none" id="line-battery" points="15,85 15,55 50,55" stroke-width="3"></polyline></g>';
	$ret.='<circle _ngcontent-c4="" cx="15" cy="85" id="canvas-battery" r="14" class="svg-'.$color.'"></circle>';
	$ret.='<text _ngcontent-c4="" fill="#ffffff" font-family="Roboto, Verdana" font-size="3.5" id="text-battery-cap" text-anchor="middle"  x="15" y="96">'.$bat_cap.' Wh</text>';
	$ret.='<svg _ngcontent-c4="" height="25" id="shape-battery" viewBox="0 0 85.04 85.04" width="25" x="2.5" y="72.5"><path _ngcontent-c4="" d="M66.98,23.292h-4.813v-4.892H48.871v4.892H36.206v-4.892H22.909v4.892h-4.85c-3.068,0-5.579,2.511-5.579,5.573v32.203c0,3.064,2.511,5.566,5.579,5.566L66.98,66.64c3.064,0,5.578-2.502,5.578-5.572V28.864C72.559,25.802,70.045,23.292,66.98,23.292z M35.875,48.401h-6.391v6.419h-4.096v-6.419h-6.394V44.33h6.394v-6.387h4.096v6.387h6.391V48.401z M64.344,48.028h-4.836h-3.863h-4.83v-4.213h4.83h3.863h4.836V48.028z" fill="#ffffff"></path></svg>';
	$ret.='<svg _ngcontent-c4="" height="6" id="gid_3cee6746_15" width="16" x="18" y="52"><g _ngcontent-c4="" id="gid_3cee6746_14"><rect _ngcontent-c4="" class="svg-'.$color.'" height="6" id="canvas-text-battery" rx="2" ry="2" width="16" x="0" y="0"></rect><text _ngcontent-c4="" fill="#ffffff" font-family="Roboto, Verdana" font-size="3.5" id="text-battery" text-anchor="middle" x="8" y="4">'.$bat.'<tspan _ngcontent-c4="" id="gid_3cee6746_15"> W</tspan></text></g></svg>';
	$ret.='<svg _ngcontent-c4="" height="6" id="gid_3cee6747_11" width="26" x="20" y="72"><g _ngcontent-c4="" id="gid_3cee6747_14"><rect _ngcontent-c4="" class="svg-grey" height="6" id="canvas-text-battery-soc" rx="2" ry="2" width="26" x="0" y="0"></rect><text _ngcontent-c4="" fill="#ffffff" font-family="Roboto, Verdana" font-size="3.5" id="text-battery-soc" text-anchor="middle" x="13" y="4">'.$soc.'%'.($soc<40?' ('.$bat_min.'%)':'').'<tspan _ngcontent-c4="" id="gid_3cee6747_15"></tspan></text></g></svg>';
	$ret.='<svg _ngcontent-c4="" height="6" id="gid_3cee6748_11" width="26" x="20" y="80"><g _ngcontent-c4="" id="gid_3cee6748_14"><rect _ngcontent-c4="" class="svg-grey" height="6" id="canvas-text-battery-cycles" rx="2" ry="2" width="26" x="0" y="0"></rect><text _ngcontent-c4="" fill="#ffffff" font-family="Roboto, Verdana" font-size="3.5" id="text-battery-cycles" text-anchor="middle" x="13" y="4">'.$cycles.'<tspan _ngcontent-c4="" id="gid_3cee6748_15"> Cycles</tspan></text></g></svg>';
	$ret.='<svg _ngcontent-c4="" height="6" id="gid_3cee6747_11" width="26" x="20" y="88"><g _ngcontent-c4="" id="gid_3cee6747_14"><rect _ngcontent-c4="" class="svg-'.($bat>0?'orange-light':'green-light').'" height="6" id="canvas-text-battery-time" rx="2" ry="2" width="26" x="0" y="0"></rect><text _ngcontent-c4="" fill="#ffffff" font-family="Roboto, Verdana" font-size="3.5" id="text-battery-time" text-anchor="middle" x="13" y="4">'.$est_time.'<tspan _ngcontent-c4="" id="gid_3cee6747_15"></tspan></text></g></svg>';
	if ($bat>0){
		$ret.='<svg _ngcontent-c4="" height="10" id="indicator-battery" viewBox="0 0 15 15" width="10" x="10" y="58"><g _ngcontent-c4="" id="indicator-battery-rot" transform="rotate(180 7.5 7.5)"><polygon _ngcontent-c4="" fill="#ffffff" points="1.5,0.5 13.5,0.5 7.5,15"></polygon><polygon _ngcontent-c4="" class="svg-orange-fill" id="indicator-battery-color" points="2,0.0 13,0.0 7.5,13.5"></polygon></g></svg>';
	}else{
		$ret.='<svg _ngcontent-c4="" height="10" id="indicator-battery" viewBox="0 0 15 15" width="10" x="10" y="58"><g _ngcontent-c4="" id="indicator-battery-rot" transform="rotate(0 7.5 7.5)"><polygon _ngcontent-c4="" fill="#ffffff" points="1.5,0.5 13.5,0.5 7.5,15"></polygon><polygon _ngcontent-c4="" class="svg-green" id="indicator-battery-color" points="2,0.0 13,0.0 7.5,13.5"></polygon></g></svg>';
	}
	$ret.='</g>';
	return $ret;
}

function createGridContainer($grid){
	if ($grid>0){
		$color="orange";
	}else{
		$color="green";
	}
	$ret='<g _ngcontent-c4="" id="GridContainer">';
	$ret.='<polyline _ngcontent-c4="" class="svg-'.$color.'-stroke" fill="none" id="line-grid" points="85,15 85,45 50,45" stroke-width="3"></polyline><circle _ngcontent-c4="" cx="85" cy="15" id="canvas-grid" r="14" class="svg-'.$color.'"></circle><svg _ngcontent-c4="" height="6" id="gid_3cee6746_27" width="16" x="66" y="42"><g _ngcontent-c4="" id="gid_3cee6746_30"><rect _ngcontent-c4="" class="svg-'.$color.'" height="6" id="canvas-text-grid" rx="2" ry="2" width="16" x="0" y="0"></rect><text _ngcontent-c4="" fill="#ffffff" font-family="Roboto, Verdana" font-size="3.5" id="text-grid" text-anchor="middle" x="8" y="4">'.$grid.'<tspan _ngcontent-c4="" id="gid_3cee6746_35"> W</tspan></text></g></svg>';
	$ret.='<svg _ngcontent-c4="" height="25" id="shape-grid" viewBox="0 0 85.04 85.04" width="25" x="72.5" y="2"><polygon _ngcontent-c4="" fill="#ffffff" points="51.239,79.899 33.679,80.163 37.28,50 17.649,50 38.329,40.512 38.849,33 21.835,33 39.919,24.06 42.509,5.043 45.075,24.041 63.062,33 46.13,33 46.651,40.541 67.39,50 47.683,50 "></polygon></svg>';
	$ret.='<svg _ngcontent-c4="" height="10" id="indicator-grid" viewBox="0 0 15 15" width="10" x="80" y="32">';
	if ($grid>0){
		$ret.='<g _ngcontent-c4="" id="indicator-grid-rot" transform="rotate(0 7.5 7.5)"><polygon _ngcontent-c4="" fill="#ffffff" points="1.5,0.5 13.5,0.5 7.5,15"></polygon><polygon _ngcontent-c4="" class="svg-orange-fill" id="indicator-grid-color" points="2,0.0 13,0.0 7.5,13.5"></polygon></g>';
	}else{
		$ret.='<g _ngcontent-c4="" id="indicator-grid-rot" transform="rotate(180 7.5 7.5)"><polygon _ngcontent-c4="" fill="#ffffff" points="1.5,0.5 13.5,0.5 7.5,15"></polygon><polygon _ngcontent-c4="" class="svg-green" id="indicator-grid-color" points="2,0.0 13,0.0 7.5,13.5"></polygon></g>';
	}
	$ret.='</svg></g>';
	return $ret;
}

function createHomeContainer($home,$specials,$special_data){
	$ret='<g _ngcontent-c4="" id="homeContainer">';
	$ret.='<polyline _ngcontent-c4="" class="svg-orange-stroke" fill="none" id="line-home" points="85,85 85,55 50,55" stroke-width="3"></polyline>';
	$ret.='<circle _ngcontent-c4="" cx="85" cy="85" id="canvas-home" r="14" class="svg-green"></circle>';
	$ret.='<svg _ngcontent-c4="" height="6" id="gid_3cee6746_43" width="16" x="66" y="52"><g _ngcontent-c4="" id="gid_3cee6746_48"><rect _ngcontent-c4="" class="svg-orange-fill" height="6" id="canvas-text-home" rx="2" ry="2" width="16" x="0" y="0"></rect><text _ngcontent-c4="" fill="#ffffff" font-family="Roboto, Verdana" font-size="3.5" id="text-home" text-anchor="middle" x="8" y="4">'.$home.'<tspan _ngcontent-c4="" id="gid_3cee6746_49"> W</tspan></text></g></svg>';
	$ret.='<svg _ngcontent-c4="" height="25" id="shape-home" viewBox="0 0 85.04 85.04" width="25" x="72.5" y="72">';
	$ret.='<path _ngcontent-c4="" d="M74.143,40.328h-4.577l-22.151-20.56c-2.704-2.692-7.091-2.779-9.755-0.12L15.317,40.328h-4.429c-2.145,0-2.627-1.042-1.068-2.526L39.679,9.575c1.563-1.481,4.115-1.439,5.68,0.042l29.854,28.165C76.78,39.267,76.298,40.328,74.143,40.328z" fill="#ffffff"></path>';
	$ret.='<path _ngcontent-c4="" d="M45.4,23.087c-1.25-1.238-3.524-1.23-4.71-0.051L18.741,43.483V72.24c0,1.654,0.91,4.314,2.562,4.314h42.431c1.652,0,3.687-2.66,3.687-4.314V43.619L45.4,23.087z M32.13,41.135c3.578,0,6.576,2.085,7.352,4.872h-2.701c-0.75-1.372-2.55-2.343-4.651-2.343c-2.1,0.001-3.899,0.971-4.651,2.343h-2.702C25.555,43.22,28.554,41.135,32.13,41.135zM42.799,67.912c-8.229,0-14.986-5.471-15.76-12.464h31.523C57.791,62.441,51.031,67.912,42.799,67.912z M58.099,46.007c-0.749-1.372-2.552-2.343-4.649-2.343c-2.101,0-3.9,0.971-4.653,2.343h-2.701c0.776-2.787,3.774-4.872,7.354-4.872c3.575,0,6.576,2.085,7.352,4.872H58.099z" fill="#ffffff"></path>';
	$ret.='</svg>';
	$ret.='<image href="pics/pic_boiler.svg" x="92" y="53"/>';
	$ret.='<image href="pics/pic_pumpe.svg" x="90" y="66"/>';
	$ret.='<image href="pics/pic_tv.svg" x="60" y="90"/>';
	$ret.='<image href="pics/pic_printer.svg" x="100" y="90"/>';
	$payload = file_get_contents('http://192.168.1.190/rpc/Shelly.GetStatus');
	$payload_data = json_decode($payload);
	if ($payload_data->{'switch:0'}->output==true){
		$ret.='<image id="lamp_01" href="pics/pic_bulp_on.svg" x="60" y="70" onclick="toggleLamp(false);"/>';
	}else{
		$ret.='<image id="lamp_01" href="pics/pic_bulp_off.svg" x="60" y="70" onclick="toggleLamp(true);"/>';
	}
	if (isset($specials) && is_array($specials)){
		for ($i=0;$i<sizeof($specials);$i++){
			if ($i<3){
				$ret.='<svg _ngcontent-c4="" height="6" id="gid_3cee6747_11" width="20" x="61" y="'.(72+($i*8)).'"><g _ngcontent-c4="" id="gid_3cee6747_14"><rect _ngcontent-c4="" class="svg-grey" height="6" id="canvas-text-home-special_'.$i.'" rx="2" ry="2" width="20" x="0" y="0"></rect><text _ngcontent-c4="" fill="#ffffff" font-family="Roboto, Verdana" font-size="2.5" id="text-home-special-'.$i.'" text-anchor="middle" x="10" y="4">'.$specials[$i].'<tspan _ngcontent-c4="" id="gid_3cee6747_15"></tspan></text></g></svg>';
			}else{
				$ret.='<svg _ngcontent-c4="" height="6" id="gid_3cee6747_11" width="20" x="85" y="'.(72+(($i-3)*8)).'"><g _ngcontent-c4="" id="gid_3cee6747_14"><rect _ngcontent-c4="" class="svg-grey" height="6" id="canvas-text-home-special_'.$i.'" rx="2" ry="2" width="20" x="0" y="0"></rect><text _ngcontent-c4="" fill="#ffffff" font-family="Roboto, Verdana" font-size="2.5" id="text-home-special-'.$i.'" text-anchor="middle" x="10" y="4">'.$specials[$i].'<tspan _ngcontent-c4="" id="gid_3cee6747_15"></tspan></text></g></svg>';
			}
		}
	}
	if (isset($special_data) && is_array($special_data)){
		$ret.='<svg _ngcontent-c4="" height="6" id="gid_special_device_01_a" width="20" x="94.5" y="48"><g _ngcontent-c4="" id="gid_special_device_01_b"><rect _ngcontent-c4="" class="svg-grey" height="6" id="canvas-text-special-device_01_a" rx="2" ry="2" width="10" x="0" y="0"></rect><text _ngcontent-c4="" fill="#ffffff" font-family="Roboto, Verdana" font-size="2.5" id="text-special-device-01_a" text-anchor="middle" x="5" y="4">'.$special_data[3].'<tspan _ngcontent-c4="" id="gid_special_device_01_c"></tspan></text></g></svg>';
		$ret.='<svg _ngcontent-c4="" height="6" id="gid_special_device_02_a" width="20" x="98.5" y="68"><g _ngcontent-c4="" id="gid_special_device_02_b"><rect _ngcontent-c4="" class="svg-grey" height="6" id="canvas-text-special-device_02_a" rx="2" ry="2" width="10" x="0" y="0"></rect><text _ngcontent-c4="" fill="#ffffff" font-family="Roboto, Verdana" font-size="2.5" id="text-special-device-02_a" text-anchor="middle" x="5" y="4">'.$special_data[2].'<tspan _ngcontent-c4="" id="gid_special_device_02_c"></tspan></text></g></svg>';
		$ret.='<svg _ngcontent-c4="" height="6" id="gid_special_device_03_a" width="20" x="68" y="94"><g _ngcontent-c4="" id="gid_special_device_03_b"><rect _ngcontent-c4="" class="svg-grey" height="6" id="canvas-text-special-device_03_a" rx="2" ry="2" width="10" x="0" y="0"></rect><text _ngcontent-c4="" fill="#ffffff" font-family="Roboto, Verdana" font-size="2.5" id="text-special-device-03_a" text-anchor="middle" x="5" y="4">'.$special_data[1].'<tspan _ngcontent-c4="" id="gid_special_device_03_c"></tspan></text></g></svg>';
		$ret.='<svg _ngcontent-c4="" height="6" id="gid_special_device_04_a" width="20" x="92" y="94"><g _ngcontent-c4="" id="gid_special_device_04_b"><rect _ngcontent-c4="" class="svg-grey" height="6" id="canvas-text-special-device_04_a" rx="2" ry="2" width="10" x="0" y="0"></rect><text _ngcontent-c4="" fill="#ffffff" font-family="Roboto, Verdana" font-size="2.5" id="text-special-device-04_a" text-anchor="middle" x="5" y="4">'.$special_data[0].'<tspan _ngcontent-c4="" id="gid_special_device_04_c"></tspan></text></g></svg>';
	}

	$ret.='<svg _ngcontent-c4="" height="10" id="indicator-home" viewBox="0 0 15 15" width="10" x="80" y="58"><g _ngcontent-c4="" id="indicator-home-rot" transform="rotate(0 7.5 7.5)"><polygon _ngcontent-c4="" fill="#ffffff" points="1.5,0.5 13.5,0.5 7.5,15"></polygon><polygon _ngcontent-c4="" class="svg-orange-fill" id="indicator-home-color" points="2,0.0 13,0.0 7.5,13.5"></polygon></g></svg>';
	$ret.='</g>';
	return $ret;
}

function createInverterPicture($dc,$dc_small,$bat,$grid,$home,$soc,$cycles,$bat_cap,$bat_min,$fchargecap,$specials,$special_data){
	$ret="<div _ngcontent-c4='' class='' style='width:1000px;'><div _ngcontent-c4='' id='test'></div>";
	$ret.='<svg _ngcontent-c4="" class="mainsvg" height="75%" id="liveSVG" viewBox="0 0 110 100" width="100%">';
	$ret.=createDCContainer($dc,$dc_small);
	$ret.=createBatterieContainer($bat,$soc,$cycles,$bat_cap,$bat_min,$fchargecap);
	$ret.=createGridContainer($grid);
	$ret.=createHomeContainer($home,$specials,$special_data);
	$ret.='<circle _ngcontent-c4="" cx="50" cy="50" id="canvas-inverter" r="14" class="svg-green"></circle>';
	$ret.='<svg _ngcontent-c4="" height="25" id="shape-inverter" viewBox="0 0 85.04 85.04" width="25" x="37.5" y="37.5">';
	$ret.='<path _ngcontent-c4="" d="M20.489,70.718c-0.179-0.707-1.472-50.964-1.472-51.419c0-1.286,2.489-3.865,4.158-5.077l1.218,61.281C22.83,74.184,20.781,71.901,20.489,70.718z" fill="#ffffff"></path>';
	$ret.='<path _ngcontent-c4="" d="M64.55,70.71c-0.319,1.258-2.583,3.728-4.171,4.997l1.226-61.673c1.646,1.11,4.417,3.901,4.417,5.265C66.022,19.753,64.726,69.994,64.55,70.71z" fill="#ffffff"></path>';
	$ret.='<path _ngcontent-c4="" d="M42.508,26.043c-3.549,0-6.436,2.886-6.436,6.437c0,3.546,2.887,6.434,6.436,6.434c3.548,0,6.435-2.888,6.435-6.434C48.943,28.929,46.056,26.043,42.508,26.043z" fill="#ffffff"></path>';
	$ret.='<path _ngcontent-c4="" d="M42.517,12.469c-5.914,0.142-12.839,0.4-16.454,0.858l1.258,63.4c3.489,0.478,10.836,0.864,13.705,0.864c0.319,0,0.604-0.006,0.845-0.011l0.631-0.028c0.171,0.011,0.436,0.022,0.655,0.028c0.247,0.005,0.53,0.011,0.845,0.011 c2.798,0,9.858-0.369,13.433-0.831l1.26-63.467C55.012,12.855,48.294,12.608,42.517,12.469z M50.244,53.027c0,1.133-0.921,2.055-2.053,2.055H36.823c-1.129,0-2.052-0.922-2.052-2.055v-5.099c0-1.128,0.923-2.05,2.052-2.05h11.368c1.131,0,2.053,0.922,2.053,2.05V53.027z M42.508,41.829c-5.155,0-9.351-4.195-9.351-9.349c0-5.157,4.196-9.351,9.351-9.351c5.155,0,9.347,4.194,9.347,9.351C51.855,37.634,47.663,41.829,42.508,41.829z" fill="#ffffff"></path>';
	$ret.='</svg></svg>';
	$ret.='</div>';
	return $ret;
}

$pv=0;$dc=0;$pv_bat=0;$bat=0;$grid=0;$home=0;$home_bat=0;$pv_small=0;$soc=0;$cycles=0;$specials=array();$home_p=0;$pv_p=0;$bat_cap;
$special_data=array();
if ($use_inverter) {
	$myfile = fopen($inverter_data, "r") or die("Unable to open file!");
	$content_inverter = fread($myfile,filesize($inverter_data));
	fclose($myfile);
	$content_inverter=substr($content_inverter,strpos($content_inverter,"{"));
	$content_inverter=str_replace("{",",",$content_inverter);
#	$content_inverter=substr($content_inverter,1,strlen($content_inverter)-3);
	$content_inverter=explode(",",str_replace(array("'"," ","}"),"",$content_inverter));
	for ($i=0;$i<sizeof($content_inverter);$i++){
		$value_pair=explode(":",$content_inverter[$i]);
		if (strcmp(trim($value_pair[0]),"PV2Bat_P")==0) $inv_pv_bat=$value_pair[1];
		if (strcmp(trim($value_pair[0]),"Home_P")==0) $inv_home_p=$value_pair[1];
		if (strcmp(trim($value_pair[0]),"HomeBat_P")==0) $inv_home_bat=$value_pair[1];
		if (strcmp(trim($value_pair[0]),"HomePv_P")==0) $inv_home_pv=$value_pair[1];
		if (strcmp(trim($value_pair[0]),"HomeGrid_P")==0) $inv_home_grid=$value_pair[1];
		if (strcmp(trim($value_pair[0]),"HomeOwn_P")==0) $inv_home_own=$value_pair[1];
		if (strcmp(trim($value_pair[0]),"Dc_P")==0) $inv_dc_p=$value_pair[1];
		if (strcmp(trim($value_pair[0]),"SoC")==0) $inv_soc=$value_pair[1];
		if (strcmp(trim($value_pair[0]),"Cycles")==0) $inv_cycles=$value_pair[1];
		if (strcmp(trim($value_pair[0]),"P")==0) $inv_bat_p=$value_pair[1];
		if (strcmp(trim($value_pair[0]),"P_pv1")==0) $inv_pv1_p=$value_pair[1];
		if (strcmp(trim($value_pair[0]),"P_pv2")==0) $inv_pv2_p=$value_pair[1];
		if (strcmp(trim($value_pair[0]),"WorkCapacity")==0) $inv_bat_cap=$value_pair[1];
		if (strcmp(trim($value_pair[0]),"FullChargeCap_E")==0) $inv_charge_cap=$value_pair[1];
	}
	$inverter.="Verbrauch von Wechselrichter erfüllt: ".substr($inv_home_own,0,strpos($inv_home_own,"."))." W<br>";
	$inverter.=" -> ".substr($inv_home_bat,0,strpos($inv_home_bat,".")). " W aus der Batterie<br>";
	$inverter.=" -> ".substr($inv_home_pv,0,strpos($inv_home_pv,".")). " W aus der Solaranlage<br>";
	$inverter.=" -> ".substr($inv_home_grid,0,strpos($inv_home_grid,"."))." W aus dem Netz<br>";
	$inverter.="Batterie<br>";
	$inverter.=" -> Lade Batterie mit ".substr($inv_pv_bat,0,strpos($inv_pv_bat,"."))." W und entlade mit ".substr($inv_bat_p,0,strpos($inv_bat_p,"."))." W<br>";
	$inverter.=" -> Ladestand: ".$inv_soc."%<br>";
	$inverter.=" -> Ladecyclen: ".$inv_cycles." <br>";
	$inverter.=" -> Kapazität: ".$inv_bat_cap." Wh<br>";
	$inverter.=" -> ChargeCap: ".$inv_charge_cap."<br>";
	$inverter.="<br>";
	$dc=(int)ceil($inv_dc_p);
	$pv=(int)ceil($inv_home_pv);
	$bat=(int)ceil($inv_bat_p);
	$home_bat=(int)ceil($inv_home_bat);
	$home_p=(int)ceil($inv_home_p);
	$pv_bat=(int)ceil($inv_pv_bat);
	$bat_cap=(int)ceil($inv_bat_cap);
	$fchargecap=(int)ceil($inv_charge_cap);
	$grid=(int)ceil($inv_home_grid);
	$home=(int)ceil($inv_home_own);
	$soc=(int)ceil($inv_soc);
	$pv_p=(int)ceil($inv_pv1_p+$inv_pv2_p);
	$cycles=(int)$inv_cycles;
}
if ($use_smart_meter) {
	$myfile = fopen($data_smart_power, "r") or die("Unable to open file!");
	$content_power = fread($myfile,filesize($data_smart_power));
	fclose($myfile);
	$myfile = fopen($data_smart_verbrauch, "r") or die("Unable to open file!");
	$content_verbrauch = fread($myfile,filesize($data_smart_verbrauch));
	fclose($myfile);
	$myfile = fopen($data_smart_einspeise, "r") or die("Unable to open file!");
	$content_einspeise = fread($myfile,filesize($data_smart_einspeise));
	fclose($myfile);

	$content_power=explode(";",$content_power);
	$smart_output=substr($content_power[1],2);
	$timestampt=$content_power[0];
	if (substr($smart_output,0,1)=="-")
        	$color="green";
	else
        	$color="red";
	$leistungen.= "<a href='index_smart.php'>Smartmeter</a>: <font color=\"".$color."\">".$smart_output." W</font><br>";
	$blocks.="<b>Details Smartmeter:</b><br>Leistung: <font color=\"".$color."\">".$smart_output." W</font><br>";

	if ($use_inverter){
		$grid = (int)$smart_output;
	}

	$content_verbrauch=explode(";",$content_verbrauch);
	$smart_output_verbrauch=substr($content_verbrauch[1],2);
	$timestampt=$content_verbrauch[0];
	$verbrauch.= "Smartmeter Verbrauch: ".(int)($smart_output_verbrauch/$scale)." kWh</font><br>";
	$blocks.="Verbrauch: ".(int)$smart_output_verbrauch." Wh<br>";

	$content_einspeise=explode(";",$content_einspeise);
	$smart_output_einspeise=substr($content_einspeise[1],2);
	$timestampt=$content_einspeise[0];
	$verbrauch.= "Smartmeter Einspeisung: ".(int)($smart_output_einspeise/$scale)." kWh</font><br>";
	$blocks.="Einspeise: ".(int)$smart_output_einspeise." Wh<br><br>";
}

for ($i=0;$i<sizeof($file->device);$i++){
	if ($file->device[$i]->attributes()->type == "1") {
		$temps.=$file->device[$i]->attributes()->name.": ".$file->device[$i]->temp." °C<br>";
		$leistungen.=$file->device[$i]->attributes()->name.": ".(($file->device[$i]->strom)/$scale)." W<br>";
		$verbrauch.=$file->device[$i]->attributes()->name.": ".(int)(($file->device[$i]->verbrauch)/$scale)." kWh<br>";
		if ($use_inverter && strcmp($dect_solar,$file->device[$i]->attributes()->ain)==0){
			$pv_small = (int)(($file->device[$i]->strom)/$scale);
		}
		for ($j=0;$j<sizeof($home_show_data);$j++){
			if ($use_inverter && strcmp($home_show_data[$j],$file->device[$i]->attributes()->ain)==0){
				array_push($specials,$file->device[$i]->attributes()->name.": ".((int)ceil(($file->device[$i]->strom)/$scale))."W");
			}
		}
		for ($j=0;$j<sizeof($home_specials);$j++){
			if ($use_inverter && strcmp($home_specials[$j],$file->device[$i]->attributes()->ain)==0){
				array_push($special_data,((int)ceil(($file->device[$i]->strom)/$scale))."W");
			}
		}


		$blocks.="<b>Details ".$file->device[$i]->attributes()->name." (AIN=".$file->device[$i]->attributes()->ain."):</b><br>";
		$blocks.="Temperatur: ".$file->device[$i]->temp." °C<br>";
		$blocks.="Leistung: ".($file->device[$i]->strom/$scale)." W<br>";
		$blocks.="Verbrauch: ".($file->device[$i]->verbrauch/$scale)." kWh<br>";
		$blocks.="<br>";
	} else if ($file->device[$i]->attributes()->type == "2") {
		$temps.=$file->device[$i]->attributes()->name.": ".$file->device[$i]->temp." °C<br>";
		
		$blocks.="<b>Details ".$file->device[$i]->attributes()->name." (AIN=".$file->device[$i]->attributes()->ain."):</b><br>";
		$blocks.="Temperatur: ".$file->device[$i]->temp." °C<br>";
		$blocks.="<br>";
	}

}
if ($use_smart_meter && $use_inverter && $show_inverter){
	#$output.=createInverterPicture($dc,$pv_small,$bat,$grid,(($dc-$pv_bat+$home_bat)+$pv_small+$grid),$soc,$cycles,$specials)."<br><br>";
	$output.=createInverterPicture($pv_p,$pv_small,$bat,$grid,($home_p>0?$home_p+$pv_small:$home_p+$pv_small),$soc,$cycles,$bat_cap,$battery_min,$fchargecap,$specials,$special_data)."<br><br>";
}

if ($use_blocks){
	$output.=$blocks."<br>";
}else{
	$output.="<input type='button' id='toggleblocks' style='font-size:3vh;background-color:grey;color:white;cursor:pointer;padding:3px;' onclick='but=document.getElementById(\"toggleblocks\");el=document.getElementById(\"nonblocks\");if(el.style.display==\"none\"){but.value=\"Details ausblenden\";el.style.display=\"\";}else{but.value=\"Details einblenden\";el.style.display=\"none\";}' value='Details einblenden'><br><br><div id='nonblocks' style='display:none'>";
	if ($use_inverter) {
		$output.=$inverter;
	}
	$output.=$leistungen."<br>";
	$output.=$verbrauch."<br>";
	$output.=$temps."<br><br>";
	$output.="</div>";
}

if ($use_smart_meter) {
	$charts.='<img src="pic_smart.php" />';
}else{
	$charts.='<img src="pic_verbrauch.php" />';
	$charts.='<img src="pic_verbrauch_monthly.php" />';
}
if ($use_inverter){
	$charts.='<img src="pic_inv_soc.php" />';
}
if ($show_energy_consumption) {
	$charts.='<img src="pic_energy_consumption_daily.php" />';
	$charts.='<img src="pic_energy_consumption_monthly.php" />';
}
if ($use_solar_power) {
	$charts.='<img src="pic_solar_power.php" />';
	$charts.='<img src="pic_solar_power_daily.php" />';
}
if ($use_solar_production) {
	$charts.='<img src="pic_solar_production.php" />';
	$charts.='<img src="pic_solar_production_monthly.php" />';
}
if ($use_warm_water) {
	$charts.='<img src="pic_warm_water.php" />';
}
$charts.='<img src="pic_energy.php" />';
$charts.='<img src="pic_temp.php" />';
if ($use_heating_temp) {
	$charts.='<img src="pic_heating_temp.php" />';
}

if ($debug) {
	$output.="<br><br>DEBUG:<br><br>";
	$output.=print_r($file,true);
}
echo "<html><head>\n";
if (!$show_charts){
	echo "<meta http-equiv=\"refresh\" content=\"".$chart_view_refresh."\">\n";
}
echo "<link rel=\"stylesheet\" href=\"index.css\">\n";
echo "<script src=\"index.js\"></script>\n";
echo "</head><body style='font-size:2vh;'>\n";
echo $output;
if ($show_charts){
	echo $charts;
}
echo "</body></html>";


?>
