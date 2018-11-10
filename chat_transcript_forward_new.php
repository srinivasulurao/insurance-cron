<?php
/**
 *  Author   : N.Srinivasulu Rao
 *  Date     : 30th-May-2016
 *  Modified : 25th-Oct-2016
 *  SUBJECT  : Send the Chat transcripts to GuardianLife Inbox via Mail, the chat transcripts will be sent as an EML attachment.
 * (c) Copyright Oracle Corporation.
 **/
 
#########################################################################
//Load Required global Variables
#########################################################################
error_reporting(E_ALL);
set_time_limit(0); //This going to run the code for unlimited time.
$server_name=str_replace(".custhelp.com","",$_SERVER['SERVER_NAME']);
$interface_name=getInterfaceName();
date_default_timezone_set('America/Chicago');
$dateTime = new DateTime();
$date= new DateTime(); // Current timezone.
$interval_end =$date->setTimestamp(($date->getTimestamp()+(3600*1)))->format("Y-m-d H:i:s");
$end_dt = $date->sub(new DateInterval('PT1H'));
$interval_start = $end_dt->format("Y-m-d H:i:s");
#########################################################################
//Display errors.
#########################################################################
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('DEBUG', true);
define('CUSTOM_SCRIPT', true);
define('PROC_DIR', '/tmp/');

#########################################################################
//Load Connect PHP & PS Log
#########################################################################
require_once(get_cfg_var('doc_root')."/ConnectPHP/Connect_init.php");
$ini = parse_ini_file('/vhosts/'.$interface_name.'/euf/assets/others/cron.ini'); 
initConnectAPI($ini['username'],$ini['password']);

require_once (get_cfg_var('doc_root') . '/custom/oracle/libraries/PSLog-2.0.php');
use PS\Log\v2\Log;
use PS\Log\v2\Severity as Severity;
use PS\Log\v2\Type as Type;
use RightNow\Connect\v1_2 as RNCPHP;

$ps_log = new Log(array(
	'type'                  => Type::Import,
	'subtype'               => "Guardian Life LIC Chat Transcript tracking !",
	'logThreshold'          => (DEBUG === true) ? Severity::Debug : Severity::Notice,
	'logToDb'               => true,
	'logToFile'             => false
));
$ps_log->logToFile(false)->logToDb(true)->stdOutputThreshold(Severity::Debug);
########################Task 1###########################################
## First Fetching the settings value, email id & the cron switch value.##
#########################################################################
try{
	$interface_id=getInterfaceId($interface_name);
	$messageBase= RightNow\Connect\v1_2\MessageBase::fetch(CUSTOM_MSG_CHAT_TRANSCRIPT_CONTACT_ID);
	$adminContactId= str_replace(' ','',$messageBase->Value);
	$admin_cids=explode(",",$adminContactId);
	$adminEmail=array();

	foreach($admin_cids as $c_id):
	  $contact=RNCPHP\Contact::fetch($c_id);
	  $adminEmail[]=($contact->Emails[0]->Address)?$contact->Emails[0]->Address:$contact->Emails[1]->Address;
	endforeach;

	$messageBase= RightNow\Connect\v1_2\MessageBase::fetch(CUSTOM_MSG_CHAT_CRON_SWITCH_VALUE);
	$cron_enabled= $messageBase->Value;

	$messageBase=RightNow\Connect\v1_2\MessageBase::fetch(CUSTOM_MSG_CHAT_CRON_FILTER_ENABLE);
	$filter_enabled=$messageBase->Value;

	$messageBase=RightNow\Connect\v1_2\MessageBase::fetch(CUSTOM_MSG_CHAT_TRANSCRIPT_EXPORT_REPORT_ID); 
	$analytics_report_id=$messageBase->Value; //Report Id. 100009 

    $messageBase=RightNow\Connect\v1_2\MessageBase::fetch(CUSTOM_MSG_CHAT_TRANSCRIPT_INCIDENT_SUBJECT_FIELDS);
	$cs_array=json_decode($messageBase->Value);

	$rp_handle=@fopen("/tmp/email_sent_new.txt","r");
	$sent=@fread($rp_handle,filesize("/tmp/email_sent_new.txt"));
	$sent=explode(",",$sent);
}
catch(RNCPHP\ConnectAPIError $err){
		$ps_log->error("Error :".$err->getMessage(). "@". $err->getLine());
}
catch(Exception $err){
		$ps_log->error("Error :".$err->getMessage(). "@". $err->getLine());
}

if($cron_enabled=="OFF"){
	$ps_log->debug("Notification : Cron has been stopped by Admin"); 
	exit;
}
else{
	
#########################################################################
//Initialise testing variable, this will manupulate the result.
#########################################################################
//unlink("/tmp/email_sent_new.txt"); //Delete the previous set file.
//$interval_start=str_replace("2018","2016",$interval_start); 
//$adminEmail=array("srini.cpchem@gmail.comddd"); 	  
//$filter_enabled="SOMETHING";

#########################################################################
//Setting the filter for reporting and iterating the list.                          
#########################################################################
	try{
		
		if($filter_enabled=="ON") {
			$filters = new RNCPHP\AnalyticsReportSearchFilterArray;
			$filter1 = new RNCPHP\AnalyticsReportSearchFilter;
			$filter1->Name = "EndTimeFilter";
			$filter1->Operator = new RightNow\Connect\v1_2\NamedIDOptList();
			$filter1->Operator->ID = 9; // this is the between operator.
			$filter1->Values = array($interval_start, $interval_end);

			$filters[] = $filter1;
			
			$filter2 = new RNCPHP\AnalyticsReportSearchFilterArray;
			$filter2 = new RNCPHP\AnalyticsReportSearchFilter;
			$filter2->Name = "InterfaceName";
			$filter2->Operator = new RightNow\Connect\v1_2\NamedIDOptList();
			$filter2->Operator->ID = 1; // this is the equal operator.
			$filter2->Values = array($interface_name);
			
			$filters[] = $filter2;

			$ar= RNCPHP\AnalyticsReport::fetch($analytics_report_id);
			$arr= $ar->run(0,$filters);
		}
		else if($filter_enabled=="OFF"){
			$ar= RNCPHP\AnalyticsReport::fetch($analytics_report_id);
			$arr= $ar->run();
		}
        else{ // Chat transcript of a particular chat ID. 
			$filters = new RNCPHP\AnalyticsReportSearchFilterArray;
			$filter = new RNCPHP\AnalyticsReportSearchFilter;
			$filter->Name = "ChatId";
			$filter->Operator = new RightNow\Connect\v1_2\NamedIDOptList();
			$filter->Operator->ID = 1; // this the between operator.
			$filter->Values = array(171034);
			$filters[] = $filter;
			$ar= RNCPHP\AnalyticsReport::fetch($analytics_report_id);
			$arr= $ar->run(0,$filters);
		}

	//debugger();

		$mail_body_pattern=getMailBodyPattern();
		$xmlStoreVar=array();
		$chat_start=array();
		$previous_agent=array();
		$involved_agents=array();
		
		for($i=0;$i<$arr->count();$i++):
			$key=(object)$arr->next();
			if($key->messageText && !in_array($key->chatId,$sent)): // Make sure there are no empty messages.
                $agent_name = $key->agentDisplayName;
				$from_name=($key->fromName)?$key->fromName:$key->firstName." ".$key->lastName; // Either Client's Name or Agent's name would be there.
				$from_name=remove_spcl_chars($from_name);
				$part_left_bn=($key->fromName)?$key->firstName.$key->lastName:remove_spcl_chars(getUserEntityName('Account',$key->agentId));
				
				if(!$key->fromName){  //this means the message is entered by a customer
                    $conversations='<p class="p1"><span class="s1">([time_stamp]) [from_name] : </span><span class="s2">[message_text] </span></p><p class="p2"><span class="s1"></span><br></p>';
                }
                else{     //this means the message is entered by an agent.
		           $conversations='<p class="p3"><span class="s1">([time_stamp]) [from_name] : </span><span class="s2">[message_text]</span></p><p class="p2"><span class="s1"></span><br></p>';
                }
				
				//We have to take care of one more condition, whether the agent is changed or not, if it is changed then we have to show the notification.
				if(!isset($previous_agent[$key->chatId]['previous_agent']) && $key->fromName){
				   $previous_agent[$key->chatId]['previous_agent']=$key->fromName;
				}
				if($previous_agent[$key->chatId]['previous_agent']!=$key->fromName && $key->fromName!=""){
				   $conversations="<p class='p1'>--- Chat transferred to Agent ".$key->fromName." ---</p><br>".$conversations;
				   $previous_agent[$key->chatId]['previous_agent']=$key->fromName;
				}
				
				//Initialising standard object, to reduce the number of columns in the report.
                $contact = RNCPHP\Contact::fetch($key->customerId);
				$incident=RNCPHP\Incident::fetch($key->incident_id);
				if($key->agentId){
				  $account=RNCPHP\Account::fetch($key->agentId);
				  $agent_email=$account->Emails[0]->Address; 
				}
				$customer_name=$contact->Name->First." ".$contact->Name->Last;
				$chat_conversation_data=array('[time_stamp]'=>str_to_timestamp($key->messageTimestamp),'[from_name]'=>$from_name,'[message_text]'=>strip_tags($key->messageText),'[message_event]'=>$messageEvent);
				$chat_question=getIncidentChatQuestion($cs_array,$incident);
				$subject=$chat_question." - Conversation #{$key->chatId} by {$customer_name} at ".date("D, Md, Y h:i:s A", strtotime(trim($key->startTime,"'")))." -0000";
                $message_unique_id=$server_name."-".$key->interfaceName."@".$key->incident_id;				
				$involved_agents[$chatId][]=($key->fromName && $agent_email)?$key->fromName.'<'.$agent_email.'>':$key->fromName;
				$ia=@implode(";",array_filter(array_unique($involved_agents[$chatId])));  
				$mail_body_data=array('[customer_email_id]'=>$contact->Emails[0]->Address, '[subject]'=>$subject, '[message_id]'=>$message_unique_id, '[recipient]'=>$ia);
				
				//Storing the details in a repository array.
				$xmlStoreVar[$key->chatId]['conversation'].=str_replace(array_keys($chat_conversation_data),array_values($chat_conversation_data),$conversations); //This will go inside the attachment.
				$xmlStoreVar[$key->chatId]['mail_body']=str_replace(array_keys($mail_body_data),array_values($mail_body_data),$mail_body_pattern); // This will be the body of the mail.
				$xmlStoreVar[$key->chatId]['agent_email']=($key->agentId)?$agent_email:"";
				$xmlStoreVar[$key->chatId]['involved_agents']=$ia; 
				$xmlStoreVar[$key->chatId]['agent_name']=$key->fromName;
				$xmlStoreVar[$key->chatId]['customer_email']=$contact->Emails[0]->Address;    
				$xmlStoreVar[$key->chatId]['customer_name']=$customer_name;  
				$xmlStoreVar[$key->chatId]['start_time']=trim($key->startTime,"'"); 
                $xmlStoreVar[$key->chatId]['end_time']=trim($key->endTime,"'");	
                $xmlStoreVar[$key->chatId]['subject']=$subject;	
                $xmlStoreVar[$key->chatId]['incident']=$incident;
                $xmlStoreVar[$key->chatId]['termination_event']=$key->termination_event;			
                $xmlStoreVar[$key->chatId]['message_unique_id']=$message_unique_id;				
				
			endif;
		endfor;

#########################################################################
//Now fire the mail                                       
#########################################################################

		$mailCounter=0;
		$mailSuccess=array();
		$mailFailed=array();
		foreach($xmlStoreVar as $key=>$value): 
		    
			try{
				$mail_body=$xmlStoreVar[$key]['mail_body']; 
				$incident=$xmlStoreVar[$key]['incident'];
				$conversation_html=$xmlStoreVar[$key]['conversation'];
				$conversation_start_time=str_to_timestamp($xmlStoreVar[$key]['start_time']);
				$conversation_end_time=str_to_timestamp($xmlStoreVar[$key]['end_time']);
				$cust_name=($xmlStoreVar[$key]['customer_name'])?$xmlStoreVar[$key]['customer_name']:"Customer";
				$left_by=(substr_count($xmlStoreVar[$key]['termination_event'],"Agent") > 0)?$xmlStoreVar[$key]['agent_name']:$xmlStoreVar[$key]['customer_name'];  
				$conversation_beginning="<p class='p1'><span class='s1'>({$conversation_start_time}) {$cust_name} : Has joined the conversation</span></p><p class='p2'><span class='s1'></span><br></p>";
				$conversation_ending="<p class='p1'><span class='s1'>({$conversation_end_time}) {$left_by} : Has left the conversation</span><span class='s2'><span class='Apple-converted-space'> </span></span></p>";
				$eml_conversation=getEMLHTML($conversation_beginning,$conversation_html, $conversation_ending);
                
				//create mail message object
				$email = new RNCPHP\Email();                
				$email->Address = $adminEmail[0];
				$email->AddressType->ID = 0;
				
				##########################################################
				//Break the loop if the loop counter reaches the count 200
				##########################################################
				if($mailCounter==201) //This means 200 mails are over, Just send 200 mails only.
				break; 

				$mm = new RNCPHP\MailMessage();
				$mm->To->EmailAddresses=$adminEmail;
				$mm->Subject = "OSvC ".$key;
				$mm->Body->Text = $mail_body;
				$mm->Options->IncludeOECustomHeaders = true;
				$mm->Headers[0]='X-AUTONOMY-SUBTYPE:OracleChat';
				$mm->Headers[1]='X-MS-Journal-Report:'." ";    
				
				$fattach = new RNCPHP\FileAttachment();
				$fattach->ContentType = "text/text";
				$fp = $fattach->makeFile();
                fwrite($fp,getEmlContent($eml_conversation,$xmlStoreVar[$key],$key));
				fclose( $fp );
				$fattach->FileName = "OSvC ".$key.".eml";
				$fattach->Name = "OSvC ".$key.".eml";
				$mm->FileAttachments[]=$fattach;
				$mm->send();
				$mailCounter++;
				
				###############################################################################################
				//Now grab the message sent or not using the mailmessage api, this is a very important process.
				###############################################################################################
				if($mm->Status->Sent){
					$sent[]=$key;  
					$mailSuccess[]=$key;
				}
				else{
					$mailFailed[]=$key;
				} 
				
				$ps_log->notice("Chat Transcript Mail Succesfully sent for Chat Id: {$key}");

			}
			catch(RNCPHP\ConnectAPIError $err){
				$ps_log->error("Connect PHP Error :".$err->getMessage(). "@". $err->getLine());
			}
			catch(Exception $err){
				$ps_log->error("Connect PHP Error :".$err->getMessage(). "@". $err->getLine());
			}
		endforeach;
	}
	catch(RNCPHP\ConnectAPIError $err){
		$ps_log->error(" Connect PHP Error :".$err->getMessage(). "@". $err->getLine());
	}
	catch(Exception $err){
		$ps_log->error(" Connect PHP Error :".$err->getMessage(). "@". $err->getLine());
	}
}

$rp_handle=@fopen("/tmp/email_sent_new.txt","w");
$sent=@fwrite($rp_handle,@implode(",",$sent));

//Now Lets Add the count to the CronRecorder CBO at the end.
//First fetch the count and then add the count to the CBO.
    $todays_date=date("Y-m-d"); 
    $cr_array=RNCPHP\CO\CronRecorder::find("ReportDate='".$todays_date."' AND Interface='".$interface_id."'");
	if(sizeof($cr_array)>0){
		$cronRecorder=RNCPHP\CO\CronRecorder::fetch($cr_array[0]->ID); 
		$cronRecorder->MailSentCount=$cr_array[0]->MailSentCount+sizeof($mailSuccess);
		$cronRecorder->MailFailedCount=$cr_array[0]->MailFailedCount+sizeof($mailFailed);
		$cronRecorder->TotalChats=$cr_array[0]->TotalChats+$mailCounter;
		$cronRecorder->FailedChats=(@implode(",",array_merge(explode(",",$cr_array[0]->FailedChats),$mailFailed)))?@implode(",",array_merge(explode(",",$cr_array[0]->FailedChats),$mailFailed)):null;
		$cronRecorder->SentChats=(@implode(",",array_merge(explode(",",$cr_array[0]->SentChats),$mailSuccess)))?@implode(",",array_merge(explode(",",$cr_array[0]->SentChats),$mailSuccess)):null;
		$cronRecorder->Interface=$interface_id;
		$cronRecorder->save(RNCPHP\RNObject::SuppressAll);
	} 
	else{
		$cronRecorder=new RNCPHP\CO\CronRecorder();
		$cronRecorder->ReportDate=time(); 
		$cronRecorder->MailSentCount=sizeof($mailSuccess);
		$cronRecorder->MailFailedCount=sizeof($mailFailed);
		$cronRecorder->TotalChats=$mailCounter;
		$cronRecorder->FailedChats=(@implode(",",$mailFailed))?@implode(",",$mailFailed):null;
		$cronRecorder->SentChats=(@implode(",",$mailSuccess))?@implode(",",$mailSuccess):null;  
		$cronRecorder->Interface=$interface_id;
		$cronRecorder->save(RNCPHP\RNObject::SuppressAll);
	}


?>


<?php

//##########################################################################
//Declaration of Important functions which are used in the chat transcript #
//##########################################################################

function debug($arrayObject,$height="30px"){
	echo "<textarea style='color:red;height:$height;width:100%'>";
	print_r($arrayObject);
	echo "</textarea>";
}


function getUserEntityName($type,$user_id){
	if($user_id) {
		if($type=="Contact")
			$user = RNCPHP\Contact::fetch($user_id);
		else
			$user= RNCPHP\Account::fetch($user_id);
		return $user->Name->First." ".$user->Name->Last;
	}
	else
		return "-NA-";

}

function str_to_timestamp_old($str){
	$str=trim($str,"'");
	$d=new DateTime($str);
	return $d->getTimestamp();
}


function str_to_timestamp($str){
	$str=trim($str,"'");
	$d=date("Y-m-dTH:i:sZ", strtotime($str));  
	
	return $d." + 0000" ;
}

function remove_spcl_chars($from_name){

	$from_name=str_replace(" ","",$from_name);
	$from_name=str_replace(".","",$from_name);
	$from_name=str_replace("_","",$from_name);
	$from_name=str_replace(":","",$from_name);
	$from_name=str_replace(";","",$from_name);

	return $from_name;
}

function debugger(){
	global $interval_start, $interval_end, $filters,$arr,$adminEmail;

	debug($interval_start."--".$interval_end);
	debug($adminEmail);
	debug($filters,"200px");
	$x=array();
	while($red=$arr->next()):
		if($red['messageText'])
			$x[]=$red;
	endwhile;
	debug($x,"600px");
	exit;

}

function getEMLHTML($conversation_beginning,$conversation_html, $conversation_ending){
	
	$eml_conversation='<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">';
	$eml_conversation.='<html><head>';
	$eml_conversation.='<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
	$eml_conversation.='<meta http-equiv="Content-Style-Type" content="text/css"><title></title>';
	$eml_conversation.='<meta name="Generator" content="Cocoa HTML Writer">';
	$eml_conversation.='<meta name="CocoaVersion" content="1504.83">';
	$eml_conversation.='<style type="text/css">';
	$eml_conversation.='p.p1 {margin: 0.0px 0.0px 0.0px 0.0px; line-height: 14.0px; font: 12.0px Times; color: #ff0000; -webkit-text-stroke: #ff0000}';
	$eml_conversation.='p.p2 {margin: 0.0px 0.0px 0.0px 0.0px; line-height: 14.0px; font: 12.0px Times; color: #000000; -webkit-text-stroke: #000000; min-height: 14.0px}';
	$eml_conversation.='p.p3 {margin: 0.0px 0.0px 0.0px 0.0px; line-height: 14.0px; font: 12.0px Times; color: #0000ff; -webkit-text-stroke: #0000ff}';
	$eml_conversation.='p.p4 {margin: 0.0px 0.0px 0.0px 0.0px; line-height: 14.0px; font: 12.0px Times; color: #000000; -webkit-text-stroke: #000000}';
	$eml_conversation.='span.s1 {font-kerning: none}';
	$eml_conversation.='span.s2 {font-kerning: none; color: #000000; -webkit-text-stroke: 0px #000000}';
	$eml_conversation.='span.s3 {font-kerning: none; color: #0000ff; -webkit-text-stroke: 0px #0000ff}';
	$eml_conversation.='</style>';
	$eml_conversation.='</head><body>';
	$eml_conversation.=$conversation_beginning.$conversation_html.$conversation_ending;
	$eml_conversation.='</body></html>';
	
	return $eml_conversation;
}

function getIncidentChatQuestion($cs_array,$incident){
	
	for($i=0;$i<sizeof($cs_array);$i++){
					$cs_subject=str_replace("Incident","incident",$cs_array->$i);
					$cs_subject=str_replace(array("customfields","customFields"),"CustomFields",$cs_subject);
				    $field_name_explode=explode(".",$cs_subject);
                    $fn_size=sizeof($field_name_explode);
					if($fn_size==2){
						$chat_question=$incident->$field_name_explode[1]; 
					}	
					if($fn_size==3){
						$chat_question=$incident->$field_name_explode[1]->$field_name_explode[2];
					}
					if($fn_size==4){
						$chat_question=$incident->$field_name_explode[1]->$field_name_explode[2]->$field_name_explode[3];
					}
					if($fn_size==5){
						$chat_question=$incident->$field_name_explode[1]->$field_name_explode[2]->$field_name_explode[3]->$field_name_explode[4];
					}
					if($chat_question=="")
						continue;
					else
						break;
   }
   
   return $chat_question;
}

function getInterfaceName(){
	$dr=$_SERVER['DOCUMENT_ROOT'];
	$dre=explode("vhosts/",$dr);
	return $dre[1]; //interface Name;
}

function getInterfaceId($interface_name){
	$intf_name=getInterfaceName();
	$res=RNCPHP\ROQL::query("SELECT ID FROM SiteInterface WHERE SiteInterface.Name='{$interface_name}'")->next();
    while($rec=$res->next()){
      return $rec['ID'];
    }
}

function getEmlContent($eml_conversation,$chat_info,$chat_id){
    $chat_info['start_time']=date("YmdTH:i:sZ",strtotime($chat_info['start_time']));	//'20180806T18:05:00Z'; 
	$base64_conversation=base64_encode($eml_conversation);
	$normal_conversation=base64_encode(strip_tags($eml_conversation));
	$eml=<<<xyz
X-MS-Journal-Report:	
MIME-Version: 1.0
X-ProofpointArchiveMediaType: Oracle
Message-ID: {$chat_info['message_unique_id']}
Subject: {$chat_info['subject']}  
From: {$chat_info['customer_name']}<{$chat_info['customer_email']}>
To: {$chat_info['involved_agents']}
Date: {$chat_info['start_time']}
Content-Type: multipart/alternative;
 boundary=--boundary_{$chat_id}_66ab67da-aca9-4484-a998-63f7da2bc406


----boundary_{$chat_id}_66ab67da-aca9-4484-a998-63f7da2bc406
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: base64

{$normal_conversation}
----boundary_{$chat_id}_66ab67da-aca9-4484-a998-63f7da2bc406
Content-Type: text/html; charset=utf-8
Content-Transfer-Encoding: base64

{$base64_conversation}
----boundary_{$chat_id}_66ab67da-aca9-4484-a998-63f7da2bc406--
xyz;

	return $eml;
}

function truncate_pslog(){
	$ps_row = RNCPHP\ROQL::queryObject("SELECT PSLog.Log from PSLog.Log")->next();
	while($ps_rec = $ps_row->next()){
    $ps_rec->destroy();
    }
	RNCPHP\ConnectAPI::commit();
}

function getMailBodyPattern(){
    $mail_body_pattern=<<<xyz
Sender  : [customer_email_id] 
Subject : [subject] 
Message-Id: [message_id]
To : [recipient]
xyz;

return $mail_body_pattern; 
}

?>