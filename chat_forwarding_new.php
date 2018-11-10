<?php
$limit=($_REQUEST['limit'])?$_REQUEST['limit']:1000;
$rp_handle=@fopen("/tmp/email_sent_new.txt","w");
$script_name=str_replace("chat_forwarding_new","chat_transcript_forward_new",$_SERVER['SCRIPT_URL']);
$running_script_url="http://".$_SERVER['SERVER_NAME'].$script_name;
@fwrite($rp_handle,"");
$limit_loop=@ceil($limit/200); //Maximum of 200 mails can be sent in one shot.
for($i=1;$i<=$limit_loop;$i++):
$output.=file_get_contents($running_script_url);
endfor;
echo $output;
?>