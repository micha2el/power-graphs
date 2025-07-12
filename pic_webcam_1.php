<?php
include("config.php");

########################################################################################
# no config below
########################################################################################
if ($use_webcam){
	$arrOpts=array("ssl"=>array("verify_peer"=>false,"verify_peer_name"=>false,),);
	if (($data = @file_get_contents($webcam_link_1,false,stream_context_create($arrOpts))) === false) {
		$error = error_get_last();
		echo "HTTP request failed. Error was: " . $error['message'];
	} else {
		header("Content-Type:image/jpeg");
		//$data=base64_encode($data);
		header("Content-Length: ".strlen($data));
		print $data;
	}
}

?>
